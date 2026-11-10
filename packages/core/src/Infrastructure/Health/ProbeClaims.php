<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Health;

use DateTimeImmutable;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Admission\ReservationInput;
use Proxyma\Domain\Execution\CompletionFact;
use Proxyma\Domain\Execution\DemandPurpose;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\HealthScope;
use Proxyma\Domain\Health\ProbeClaim;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Database\DatabaseClock;
use Proxyma\Infrastructure\Database\PackageSchema;

/** @internal Все строки health уже заблокированы в общем порядке admission. */
final readonly class ProbeClaims
{
    public function __construct(private ConnectionScope $scope) {}

    public function find(string $token): ?object
    {
        return $this->scope->connection->table((new ProbeClaimRecord)->getTable())->where('id', $token)->first();
    }

    public function ownsCompletion(CompletionFact $fact): bool
    {
        $s = $fact->summary;
        if (($s['purpose'] ?? null) !== 'probe' || ! isset($s['probe_token'])) {
            return false;
        }
        $claim = $this->find($s['probe_token']);
        $attempt = $this->scope->connection->table(PackageSchema::tables()['attempts'])->where('id', $fact->attemptId->value)->first();

        return $claim !== null && $claim->tenant_key === $fact->tenant->value
            && $claim->physical_attempt_id === $attempt?->physical_attempt_id
            && $claim->operation_id === $attempt?->operation_id
            && json_decode($claim->epochs, true, flags: JSON_THROW_ON_ERROR) === ($s['probe_epochs'] ?? [])
            && $this->owns($claim, $this->rows($fact->authorizationEpochs->epochs));
    }

    /** @param array<string, int> $epochs
     * @return array<string, object>
     */
    public function rows(array $epochs): array
    {
        $rows = [];
        foreach ($epochs as $key => $epoch) {
            [$name, $id] = explode(':', $key, 2);
            $table = PackageSchema::tables()[HealthScope::from($name)->value];
            $row = $this->scope->connection->table($table)->where('id', $id)->first();
            if ($row !== null) {
                $rows[$key] = $row;
            }
        }

        return $rows;
    }

    public function assertAdmission(ReservationInput $input, array $epochs): void
    {
        if ($input->operation->purpose !== DemandPurpose::Probe) {
            return;
        }
        $token = $input->operation->probeToken->value;
        $claim = $this->find($token);
        if ($claim === null || $claim->tenant_key !== $input->operation->owner->tenantKey->value
            || $claim->operation_id !== $input->operation->operationId->value
            || $claim->route_id !== $input->route->id->value || $claim->target_id !== $input->target->id->value
            || $claim->physical_attempt_id !== null || ! $this->owns($claim, $this->rows($epochs))) {
            throw new AdmissionRejected('probe_ownership_lost');
        }
    }

    /** Exact equality, including all half-open rows on the selected path. */
    public function owns(object $claim, array $rows): bool
    {
        $now = DatabaseClock::now($this->scope->connection);
        $epochs = json_decode($claim->epochs, true, flags: JSON_THROW_ON_ERROR);
        $pin = new ProbeClaim(new Uuid($claim->id), $epochs, new DateTimeImmutable($claim->expires_at));
        if ($claim->purpose !== 'probe' || $pin->until <= $now) {
            return false;
        }
        $live = [];
        foreach ($rows as $key => $row) {
            if ($row->state !== 'half_open') {
                continue;
            }
            if ($row->probe_token !== $claim->id || $row->probe_until === null
                || new DateTimeImmutable($row->probe_until) != $pin->until) {
                return false;
            }
            $live[$key] = (int) $row->health_epoch;
        }
        ksort($live, SORT_STRING);

        return $live === $pin->epochs->epochs;
    }

    public function consume(ReservationInput $input): array
    {
        if ($input->operation->purpose !== DemandPurpose::Probe) {
            return [];
        }
        $claim = $this->find($input->operation->probeToken->value);
        $updated = $this->scope->connection->table((new ProbeClaimRecord)->getTable())->where('id', $claim->id)
            ->whereNull('physical_attempt_id')->whereRaw('expires_at > clock_timestamp()')
            ->update(['physical_attempt_id' => $input->request->physicalAttemptId->value]);
        if ($updated !== 1) {
            throw new AdmissionRejected('probe_ownership_lost');
        }

        return json_decode($claim->epochs, true, flags: JSON_THROW_ON_ERROR);
    }
}
