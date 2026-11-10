<?php

declare(strict_types=1);

namespace Proxyma\Application\Admission;

use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Admission\AuthorityLockTier;
use Proxyma\Domain\Admission\QueueLimits;
use Proxyma\Domain\Execution\OperationCorrelation;
use Proxyma\Domain\Execution\RequestTimeoutPolicy;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\DatabaseClock;
use Proxyma\Infrastructure\Database\PackageSchema;

final readonly class OperationCommands
{
    public function __construct(private TransactionCoordinator $transactions, private AuthorityLocker $locks,
        private CatalogAccess $access, private QueueLimits $limits = new QueueLimits) {}

    public function create(OperationCorrelation $operation, Uuid $profileId, Uuid $targetId, RequestTimeoutPolicy $timeouts, ?Uuid $parentAttemptId = null): OperationCorrelation
    {
        $scope = $this->transactions->scope;
        $scope->assertOutsideTransaction();
        $tenant = $operation->owner->tenantKey;
        $this->access->authorize($tenant, 'execution.operation.create', $operation->operationId);
        $intent = hash('sha256', json_encode([$profileId->value, $targetId->value, $operation->owner->ownerType, $operation->owner->ownerId,
            hash('sha256', $operation->owner->token()), $operation->owner->generation, $operation->deadlineAt->format('U.u'),
            $operation->externalOperationKey, $parentAttemptId?->value, $operation->purpose->value, $operation->probeToken?->value,
            $timeouts->connectTimeoutMs, $timeouts->totalTimeoutMs, $timeouts->maxAttempts], JSON_THROW_ON_ERROR));

        return $this->transactions->run(function ($tx) use ($operation, $profileId, $targetId, $timeouts, $parentAttemptId, $tenant, $intent, $scope): OperationCorrelation {
            $this->locks->enter(AuthorityLockTier::Operation, $operation->operationId->value, $tx);
            $db = $scope->connection;
            // Новый root ещё не существует: provisioning mutex сериализует его создание.
            $db->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['proxyma:operation:'.$tenant->value.':'.$operation->operationId->value]);
            $table = $db->table(PackageSchema::tables()['operations']);
            $existing = (clone $table)->where('tenant_key', $tenant->value)->where('id', $operation->operationId->value)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->intent_hash !== $intent) {
                    throw new AdmissionRejected('operation_identity_conflict');
                }

                return $operation;
            }
            $now = DatabaseClock::now($db);
            $duration = DatabaseClock::micros($operation->deadlineAt) - DatabaseClock::micros($now);
            if ($duration < $timeouts->totalWithCleanupMarginNs() / 1000 || $duration > $this->limits->maxOperationMs * 1000) {
                throw new AdmissionRejected('operation_deadline_out_of_bounds');
            }
            $table->insert(['id' => $operation->operationId->value, 'tenant_key' => $tenant->value,
                'profile_id' => $profileId->value, 'target_id' => $targetId->value, 'owner_type' => $operation->owner->ownerType,
                'owner_id' => $operation->owner->ownerId, 'owner_token' => $operation->owner->token(), 'owner_generation' => $operation->owner->generation,
                'deadline' => $operation->deadlineAt->format('Y-m-d H:i:s.uP'), 'connect_timeout_ms' => $timeouts->connectTimeoutMs,
                'request_timeout_ms' => $timeouts->totalTimeoutMs, 'max_physical_attempts' => $timeouts->maxAttempts,
                'external_operation_key' => $operation->externalOperationKey, 'parent_attempt_id' => $parentAttemptId?->value, 'intent_hash' => $intent]);

            return $operation;
        });
    }

    public function cancel(TenantKey $tenant, Uuid $id, int $expectedRevision): void
    {
        $this->transactions->scope->assertOutsideTransaction();
        $this->access->authorize($tenant, 'execution.operation.cancel', $id);
        $this->transactions->run(function ($tx) use ($tenant, $id, $expectedRevision): void {
            $row = $this->locks->rows(AuthorityLockTier::Operation, $tenant, [$id->value], $tx)[0];
            if ((int) $row->revision !== $expectedRevision) {
                throw new AdmissionRejected('operation_revision_conflict');
            }
            if ($row->status !== 'active') {
                return;
            }
            $db = $this->transactions->scope->connection;
            $db->table(PackageSchema::tables()['operations'])->where('tenant_key', $tenant->value)->where('id', $id->value)
                ->update(['status' => 'cancelled', 'revoked_at' => $db->raw('clock_timestamp()'), 'revision' => $expectedRevision + 1]);
        });
    }
}
