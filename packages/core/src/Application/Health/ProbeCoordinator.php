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
use Proxyma\Domain\Health\HealthScope;
use Proxyma\Domain\Health\ProbeClaim;
use Proxyma\Infrastructure\Admission\ReservationCatalogLocks;
use Proxyma\Infrastructure\Database\DatabaseClock;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Health\ProbeClaimRecord;
use Proxyma\Infrastructure\Health\ProbeClaims;

final readonly class ProbeCoordinator
{
    public function __construct(private TransactionCoordinator $transactions, private AuthorityLocker $locks,
        private OwnerAuthority $owners, private CatalogAccess $access, private ReservationCatalogLocks $catalog) {}

    public function claim(ReservationInput $input, int $leaseSeconds = 60): ProbeClaim
    {
        $scope = $this->transactions->scope;
        $scope->assertOutsideTransaction();
        if ($input->operation->purpose !== DemandPurpose::Probe || $leaseSeconds < 1 || $leaseSeconds > 300) {
            throw new InvalidArgumentException('Probe purpose and lease in 1..300 seconds are required.');
        }
        $this->access->authorize($input->operation->owner->tenantKey, 'health.probe.claim', $input->route->id);
        $claim = $this->transactions->run(function ($tx) use ($input, $scope, $leaseSeconds): ?ProbeClaim {
            $owner = $this->locks->owner($this->owners, $input->operation->owner, $input->ownerSnapshot, $tx);
            if ($owner->purpose !== DemandPurpose::Probe || $owner->probeToken?->value !== $input->operation->probeToken->value) {
                throw new AdmissionRejected('probe_owner_conflict');
            }
            $root = $this->locks->operation($input->operation->operationId, $input->operation->owner, $tx);
            if ($root->profile_id !== $input->profile->id->value || $root->target_id !== $input->target->id->value) {
                throw new AdmissionRejected('probe_intent_conflict');
            }
            $evidence = $this->catalog->lock($input, $tx, probeClaim: true);
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
                if ($row->credential_endpoint_id ?? false || str_starts_with($row->reason_code ?? '', 'credential_hold')) {
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

            return $claim;
        });

        return $claim ?? throw new AdmissionRejected('probe_expired_backoff');
    }
}
