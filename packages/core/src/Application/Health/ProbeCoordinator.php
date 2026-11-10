<?php

declare(strict_types=1);

namespace Proxyma\Application\Health;

use DateTimeImmutable;
use InvalidArgumentException;
use Proxyma\Application\Admission\AuthorityLocker;
use Proxyma\Application\Admission\TransactionCoordinator;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Contracts\OwnerAuthority;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Admission\ReservationInput;
use Proxyma\Domain\Execution\DemandPurpose;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\HealthScope;
use Proxyma\Domain\Health\ProbeClaim;
use Proxyma\Infrastructure\Admission\ReservationCatalogLocks;
use Proxyma\Infrastructure\Audit\CatalogAudit;
use Proxyma\Infrastructure\Database\DatabaseClock;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Health\ProbeClaimRecord;
use Proxyma\Infrastructure\Health\ProbeClaims;

final readonly class ProbeCoordinator
{
    public function __construct(private TransactionCoordinator $transactions, private AuthorityLocker $locks,
        private OwnerAuthority $owners, private CatalogAccess $access, private ReservationCatalogLocks $catalog) {}

    public function claim(ReservationInput $input, int $leaseSeconds = 60, ?ProbeReview $review = null): ProbeClaim
    {
        $scope = $this->transactions->scope;
        $scope->assertOutsideTransaction();
        if ($input->operation->purpose !== DemandPurpose::Probe || $leaseSeconds < 1 || $leaseSeconds > 300) {
            throw new InvalidArgumentException('Probe purpose and lease in 1..300 seconds are required.');
        }
        $this->access->authorize($input->operation->owner->tenantKey, 'health.probe.claim', $input->route->id, $review?->actor->actor);
        $claim = $this->transactions->run(function ($tx) use ($input, $scope, $leaseSeconds, $review): ?ProbeClaim {
            $owner = $this->locks->owner($this->owners, $input->operation->owner, $input->ownerSnapshot, $tx);
            if ($owner->purpose !== DemandPurpose::Probe || $owner->probeToken?->value !== $input->operation->probeToken->value) {
                throw new AdmissionRejected('probe_owner_conflict');
            }
            $root = $this->locks->operation($input->operation->operationId, $input->operation->owner, $tx);
            if ($root->profile_id !== $input->profile->id->value || $root->target_id !== $input->target->id->value) {
                throw new AdmissionRejected('probe_intent_conflict');
            }
            $evidence = $this->catalog->lock($input, $tx, probeClaim: true);
            if ($review !== null) {
                $receipt = $scope->connection->table(PackageSchema::tables()['catalog_command_receipts'])
                    ->where('tenant_key', $input->operation->owner->tenantKey->value)
                    ->where('idempotency_key', CatalogAudit::key($review->actor->idempotencyKey))->exists();
                if ($receipt) {
                    throw new AdmissionRejected('probe_command_already_claimed');
                }
                $review->assertMatches(['versions' => $evidence['versions'] + ['policy_hash' => $input->policy->policyHash->hex], 'epochs' => $evidence['epochs']]);
            }
            $repository = new ProbeClaims($scope);
            $token = $input->operation->probeToken;
            if ($repository->find($token->value) !== null) {
                throw new AdmissionRejected('probe_token_reused');
            }
            $rows = $repository->rows($evidence['epochs']);
            $now = DatabaseClock::now($scope->connection);
            $until = min($now->modify('+'.$leaseSeconds.' seconds'), $input->operation->deadlineAt, $owner->deadlineAt);
            if ($until <= $now) {
                throw new AdmissionRejected('probe_deadline');
            }
            $blocked = [];
            $expired = [];
            foreach ($rows as $key => $row) {
                if ($row->state === 'closed') {
                    continue;
                }
                if (($row->credential_endpoint_id ?? false) || str_starts_with($row->reason_code ?? '', 'credential_hold')) {
                    throw new AdmissionRejected('credential_hold');
                }
                if ($row->state === 'half_open') {
                    if ($row->probe_until !== null && new DateTimeImmutable($row->probe_until) <= $now) {
                        $expired[$key] = $row;

                        continue;
                    }
                    throw new AdmissionRejected('probe_already_owned');
                }
                if ($row->open_until !== null && new DateTimeImmutable($row->open_until) > $now) {
                    throw new AdmissionRejected('probe_not_due');
                }
                // Open с NULL deadline — постоянный hold, а не приглашение к probe.
                if ($row->state === 'open' && $row->open_until === null) {
                    throw new AdmissionRejected('health_hold');
                }
                $blocked[$key] = $row;
            }
            if ($expired !== []) {
                foreach ($expired as $key => $row) {
                    [$name] = explode(':', $key, 2);
                    $delay = min(300, 30 * 2 ** min(4, (int) $row->recent_openings));
                    $patch = [
                        'state' => 'open', 'health_epoch' => $row->health_epoch + 1, 'state_version' => $row->state_version + 1,
                        'probe_token' => null, 'probe_until' => null, 'open_until' => $now->modify('+'.$delay.' seconds'),
                        'recent_openings' => $row->recent_openings + 1, 'reason_code' => 'probe_expired',
                    ];
                    if ($name === HealthScope::Target->value) {
                        $patch['availability'] = 'unavailable';
                    }
                    $scope->connection->table(PackageSchema::tables()[$name])->where('id', $row->id)->update($patch);
                }

                return null;
            }
            $epochs = [];
            foreach ($blocked as $key => $row) {
                $epochs[$key] = (int) $row->health_epoch + 1;
            }
            if ($epochs === []) {
                throw new AdmissionRejected('probe_scope_empty');
            }
            $claim = new ProbeClaim($token, $epochs, $until);
            $scope->connection->table((new ProbeClaimRecord)->getTable())->insert([
                'id' => $token->value, 'tenant_key' => $input->operation->owner->tenantKey->value,
                'operation_id' => $input->operation->operationId->value, 'route_id' => $input->route->id->value,
                'target_id' => $input->target->id->value, 'epochs' => json_encode($claim->epochs->epochs, JSON_THROW_ON_ERROR),
                'created_at' => $now, 'expires_at' => $until,
            ]);
            foreach ($blocked as $key => $row) {
                [$name] = explode(':', $key, 2);
                $changed = $scope->connection->table(PackageSchema::tables()[HealthScope::from($name)->value])
                    ->where('id', $row->id)->where('health_epoch', $row->health_epoch)->where('state_version', $row->state_version)
                    ->update(['state' => 'half_open', 'health_epoch' => $claim->epochs->epochs[$key],
                        'state_version' => $row->state_version + 1, 'probe_token' => $token->value, 'probe_until' => $until, 'open_until' => null]);
                if ($changed !== 1) {
                    throw new AdmissionRejected('probe_cas_lost');
                }
            }
            if ($review !== null) {
                $first = array_key_first($blocked);
                [$name, $id] = explode(':', $first, 2);
                $row = $blocked[$first];
                $t = PackageSchema::tables();
                $tenant = $input->operation->owner->tenantKey->value;
                $auditId = Uuid::random()->value;
                $key = CatalogAudit::key($review->actor->idempotencyKey);
                $result = json_encode(['status' => 'probe_requested', 'operation_id' => $input->operation->operationId->value,
                    'diagnose_before_any_new_attempt' => true, 'scope_epochs' => $review->epochs, 'scope_versions' => $review->versions], JSON_THROW_ON_ERROR);
                $scope->connection->table($t['audit_events'])->insert(['id' => $auditId, 'tenant_key' => $tenant,
                    'actor_type' => $review->actor->actor->type, 'actor_id' => hash('sha256', $review->actor->actorId),
                    'reason' => hash('sha256', $review->actor->reason), 'ability' => 'health.probe.execute',
                    'entity_type' => $name, 'entity_id' => $id, 'previous_revision' => $row->state_version,
                    'revision' => (int) $row->state_version + 1, 'idempotency_key' => $key, 'command_hash' => $review->hash,
                    'result' => $result]);
                $scope->connection->table($t['catalog_command_receipts'])->insert(['tenant_key' => $tenant,
                    'audit_event_id' => $auditId, 'idempotency_key' => $key, 'command_hash' => $review->hash, 'result' => $result]);
            }

            return $claim;
        });

        return $claim ?? throw new AdmissionRejected('probe_expired_backoff');
    }
}
