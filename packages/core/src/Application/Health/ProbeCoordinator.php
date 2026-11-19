<?php

declare(strict_types=1);

namespace Proxyma\Application\Health;

use DateTimeImmutable;
use InvalidArgumentException;
use Packman\Attributes\Api;
use Proxyma\Application\Admission\AuthorityLocker;
use Proxyma\Application\Admission\TransactionCoordinator;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Contracts\Admission\OwnerAuthority;
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
use Proxyma\Infrastructure\Health\ProbeClaims;
use Proxyma\Internal\Database\RowValues;

#[Api(since: '1.0')]
final readonly class ProbeCoordinator
{
    public function __construct(private TransactionCoordinator $transactions, private AuthorityLocker $locks,
        private OwnerAuthority $owners, private CatalogAccess $access, private ReservationCatalogLocks $catalog) {}

    public function claim(ReservationInput $input, int $leaseSeconds = 60, ?ProbeReview $review = null): ProbeClaim
    {
        $scope = $this->transactions->scope;
        $scope->assertOutsideTransaction();
        if ($input->operation->purpose !== DemandPurpose::Probe || $input->operation->probeToken === null || $leaseSeconds < 1 || $leaseSeconds > 300) {
            throw new InvalidArgumentException('Probe purpose and lease in 1..300 seconds are required.');
        }
        $token = $input->operation->probeToken;
        $reviewIdempotencyKey = $review?->actor->idempotencyKey;
        if ($review !== null && $reviewIdempotencyKey === null) {
            throw new InvalidArgumentException('Probe review requires idempotencyKey.');
        }
        $this->access->authorize($input->operation->owner->tenantKey, 'health.probe.claim', $input->route->id, $review?->actor->actor);
        $claim = $this->transactions->run(function ($tx) use ($input, $scope, $leaseSeconds, $review, $reviewIdempotencyKey, $token): ?ProbeClaim {
            $owner = $this->locks->owner($this->owners, $input->operation->owner, $input->ownerSnapshot, $tx);
            if ($owner->purpose !== DemandPurpose::Probe || $owner->probeToken?->value !== $token->value) {
                throw new AdmissionRejected('probe_owner_conflict');
            }
            $root = $this->locks->operation($input->operation->operationId, $input->operation->owner, $tx);
            if (RowValues::colStr($root, 'profile_id') !== $input->profile->id->value || RowValues::colStr($root, 'target_id') !== $input->target->id->value) {
                throw new AdmissionRejected('probe_intent_conflict');
            }
            $evidence = $this->catalog->lock($input, $tx, probeClaim: true);
            if ($review !== null) {
                $receipt = PackageSchema::query($scope->connection, 'catalog_command_receipts')
                    ->where('tenant_key', $input->operation->owner->tenantKey->value)
                    ->where('idempotency_key', CatalogAudit::key($reviewIdempotencyKey))->exists();
                if ($receipt) {
                    throw new AdmissionRejected('probe_command_already_claimed');
                }
                $review->assertMatches(['versions' => $evidence['versions'] + ['policy_hash' => $input->policy->policyHash->hex], 'epochs' => $evidence['epochs']]);
            }
            $repository = new ProbeClaims($scope);
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
                $state = RowValues::colStr($row, 'state');
                if ($state === 'closed') {
                    continue;
                }
                if (RowValues::colNullableStr($row, 'credential_endpoint_id') !== null
                    || str_starts_with(RowValues::colNullableStr($row, 'reason_code') ?? '', 'credential_hold')) {
                    throw new AdmissionRejected('credential_hold');
                }
                if ($state === 'half_open') {
                    $probeUntil = RowValues::colNullableStr($row, 'probe_until');
                    if ($probeUntil !== null && new DateTimeImmutable($probeUntil) <= $now) {
                        $expired[$key] = $row;

                        continue;
                    }
                    throw new AdmissionRejected('probe_already_owned');
                }
                $openUntil = RowValues::colNullableStr($row, 'open_until');
                if ($openUntil !== null && new DateTimeImmutable($openUntil) > $now) {
                    throw new AdmissionRejected('probe_not_due');
                }
                // Open с NULL deadline — постоянный hold, а не приглашение к probe.
                if ($state === 'open' && $openUntil === null) {
                    throw new AdmissionRejected('health_hold');
                }
                $blocked[$key] = $row;
            }
            if ($expired !== []) {
                foreach ($expired as $key => $row) {
                    [$name] = explode(':', $key, 2);
                    $recentOpenings = RowValues::colInt($row, 'recent_openings');
                    $delay = min(300, 30 * 2 ** min(4, $recentOpenings));
                    $patch = [
                        'state' => 'open',
                        'health_epoch' => RowValues::colInt($row, 'health_epoch') + 1,
                        'state_version' => RowValues::colInt($row, 'state_version') + 1,
                        'probe_token' => null,
                        'probe_until' => null,
                        'open_until' => $now->modify('+'.$delay.' seconds'),
                        'recent_openings' => $recentOpenings + 1,
                        'reason_code' => 'probe_expired',
                    ];
                    if ($name === HealthScope::Target->value) {
                        $patch['availability'] = 'unavailable';
                    }
                    PackageSchema::query($scope->connection, $name)->where('id', RowValues::colStr($row, 'id'))->update($patch);
                }

                return null;
            }
            $epochs = [];
            foreach ($blocked as $key => $row) {
                $epochs[$key] = RowValues::colInt($row, 'health_epoch') + 1;
            }
            if ($epochs === []) {
                throw new AdmissionRejected('probe_scope_empty');
            }
            $claim = new ProbeClaim($token, $epochs, $until);
            PackageSchema::query($scope->connection, 'probe_claims')->insert([
                'id' => $token->value, 'tenant_key' => $input->operation->owner->tenantKey->value,
                'operation_id' => $input->operation->operationId->value, 'route_id' => $input->route->id->value,
                'target_id' => $input->target->id->value, 'epochs' => json_encode($claim->epochs->epochs, JSON_THROW_ON_ERROR),
                'created_at' => $now, 'expires_at' => $until,
            ]);
            foreach ($blocked as $key => $row) {
                [$name] = explode(':', $key, 2);
                $stateVersion = RowValues::colInt($row, 'state_version');
                $changed = $scope->connection->table(PackageSchema::table(HealthScope::from($name)->value, $scope->connection))
                    ->where('id', RowValues::colStr($row, 'id'))
                    ->where('health_epoch', RowValues::colInt($row, 'health_epoch'))
                    ->where('state_version', $stateVersion)
                    ->update([
                        'state' => 'half_open',
                        'health_epoch' => $claim->epochs->epochs[$key],
                        'state_version' => $stateVersion + 1,
                        'probe_token' => $token->value,
                        'probe_until' => $until,
                        'open_until' => null,
                    ]);
                if ($changed !== 1) {
                    throw new AdmissionRejected('probe_cas_lost');
                }
            }
            if ($review !== null) {
                $first = array_key_first($blocked) ?? throw new AdmissionRejected('probe_scope_empty');
                [$name, $id] = explode(':', $first, 2);
                $row = $blocked[$first];
                $stateVersion = RowValues::colInt($row, 'state_version');
                $t = PackageSchema::tables($this->transactions->scope->connection);
                $tenant = $input->operation->owner->tenantKey->value;
                $auditId = Uuid::random()->value;
                $key = CatalogAudit::key($reviewIdempotencyKey);
                $result = json_encode(['status' => 'probe_requested', 'operation_id' => $input->operation->operationId->value,
                    'diagnose_before_any_new_attempt' => true, 'scope_epochs' => $review->epochs, 'scope_versions' => $review->versions], JSON_THROW_ON_ERROR);
                $scope->connection->table($t['audit_events'])->insert(['id' => $auditId, 'tenant_key' => $tenant,
                    'actor_type' => $review->actor->actor->type, 'actor_id' => hash('sha256', $review->actor->actorId),
                    'reason' => hash('sha256', $review->actor->reason), 'ability' => 'health.probe.execute',
                    'entity_type' => $name, 'entity_id' => $id, 'previous_revision' => $stateVersion,
                    'revision' => $stateVersion + 1, 'idempotency_key' => $key, 'command_hash' => $review->hash,
                    'result' => $result]);
                $scope->connection->table($t['catalog_command_receipts'])->insert(['tenant_key' => $tenant,
                    'audit_event_id' => $auditId, 'idempotency_key' => $key, 'command_hash' => $review->hash, 'result' => $result]);
            }

            return $claim;
        });

        return $claim ?? throw new AdmissionRejected('probe_expired_backoff');
    }
}
