<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Health;

use DateTimeImmutable;
use Packman\Attributes\Internal;
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
use Proxyma\Internal\Database\RowValues;

/** @internal Все строки health уже заблокированы в общем порядке admission. */
#[Internal]
final readonly class ProbeClaims
{
    public function __construct(private ConnectionScope $scope) {}

    public function find(string $token): ?object
    {
        return PackageSchema::query($this->scope->connection, 'probe_claims')->where('id', $token)->first();
    }

    public function ownsCompletion(CompletionFact $fact): bool
    {
        $s = $fact->summary;
        if (($s['purpose'] ?? null) !== 'probe' || ! is_string($s['probe_token'] ?? null)) {
            return false;
        }
        $claim = $this->find(RowValues::str($s['probe_token']));
        $attempt = PackageSchema::query($this->scope->connection, 'attempts')->where('id', $fact->attemptId->value)->first();

        return $claim !== null
            && $attempt !== null
            && RowValues::colStr($claim, 'tenant_key') === $fact->tenant->value
            && RowValues::colNullableStr($claim, 'physical_attempt_id') === RowValues::colNullableStr($attempt, 'physical_attempt_id')
            && RowValues::colNullableStr($claim, 'operation_id') === RowValues::colNullableStr($attempt, 'operation_id')
            && $this->decodeEpochs(RowValues::colStr($claim, 'epochs')) === ($s['probe_epochs'] ?? [])
            && $this->owns($claim, $this->rows($fact->authorizationEpochs->epochs));
    }

    /**
     * @param  array<string, int>  $epochs
     * @return array<string, object>
     */
    public function rows(array $epochs): array
    {
        $rows = [];
        foreach ($epochs as $key => $epoch) {
            [$name, $id] = explode(':', $key, 2);
            $table = PackageSchema::table(HealthScope::from($name)->value, $this->scope->connection);
            $row = $this->scope->connection->table($table)->where('id', $id)->first();
            if ($row !== null) {
                $rows[$key] = $row;
            }
        }

        return $rows;
    }

    /** @param array<string, int> $epochs */
    public function assertAdmission(ReservationInput $input, array $epochs): void
    {
        if ($input->operation->purpose !== DemandPurpose::Probe) {
            return;
        }
        $probeToken = $input->operation->probeToken;
        if ($probeToken === null) {
            throw new AdmissionRejected('probe_ownership_lost');
        }
        $claim = $this->find($probeToken->value);
        if ($claim === null
            || RowValues::colStr($claim, 'tenant_key') !== $input->operation->owner->tenantKey->value
            || RowValues::colNullableStr($claim, 'operation_id') !== $input->operation->operationId->value
            || RowValues::colNullableStr($claim, 'route_id') !== $input->route->id->value
            || RowValues::colNullableStr($claim, 'target_id') !== $input->target->id->value
            || RowValues::col($claim, 'physical_attempt_id') !== null
            || ! $this->owns($claim, $this->rows($epochs))) {
            throw new AdmissionRejected('probe_ownership_lost');
        }
    }

    /**
     * Exact equality, including all half-open rows on the selected path.
     *
     * @param  array<string, object>  $rows
     */
    public function owns(object $claim, array $rows): bool
    {
        $now = DatabaseClock::now($this->scope->connection);
        $epochs = $this->decodeEpochs(RowValues::colStr($claim, 'epochs'));
        $claimId = RowValues::colStr($claim, 'id');
        $pin = new ProbeClaim(new Uuid($claimId), $epochs, new DateTimeImmutable(RowValues::colStr($claim, 'expires_at')));
        if (RowValues::colStr($claim, 'purpose') !== 'probe' || $pin->until <= $now) {
            return false;
        }
        $live = [];
        foreach ($rows as $key => $row) {
            if (RowValues::colStr($row, 'state') !== 'half_open') {
                continue;
            }
            $probeUntil = RowValues::colNullableStr($row, 'probe_until');
            if (RowValues::colNullableStr($row, 'probe_token') !== $claimId || $probeUntil === null
                || DatabaseClock::micros(new DateTimeImmutable($probeUntil)) !== DatabaseClock::micros($pin->until)) {
                return false;
            }
            $live[$key] = RowValues::colInt($row, 'health_epoch');
        }
        ksort($live, SORT_STRING);

        return $live === $pin->epochs->epochs;
    }

    /** @return array<string, int> */
    public function consume(ReservationInput $input): array
    {
        if ($input->operation->purpose !== DemandPurpose::Probe) {
            return [];
        }
        $probeToken = $input->operation->probeToken;
        if ($probeToken === null) {
            throw new AdmissionRejected('probe_ownership_lost');
        }
        $claim = $this->find($probeToken->value) ?? throw new AdmissionRejected('probe_ownership_lost');
        $updated = PackageSchema::query($this->scope->connection, 'probe_claims')->where('id', RowValues::colStr($claim, 'id'))
            ->whereNull('physical_attempt_id')->whereRaw('expires_at > clock_timestamp()')
            ->update(['physical_attempt_id' => $input->request->physicalAttemptId->value]);
        if ($updated !== 1) {
            throw new AdmissionRejected('probe_ownership_lost');
        }

        return $this->decodeEpochs(RowValues::colStr($claim, 'epochs'));
    }

    /** @return array<string, int> */
    private function decodeEpochs(string $json): array
    {
        $epochs = [];
        foreach (RowValues::jsonAssoc($json) as $scopeKey => $epoch) {
            $epochs[$scopeKey] = RowValues::int($epoch);
        }

        return $epochs;
    }
}
