<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Admission;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Api;
use Proxyma\Contracts\Admission\OwnerAuthority;
use Proxyma\Contracts\Admission\SqlConnectionParticipant;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Execution\DemandPurpose;
use Proxyma\Domain\Execution\LockedOwnerState;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\OwnerRef;
use Proxyma\Domain\Execution\OwnerSnapshot;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Database\DatabaseClock;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Internal\Database\RowValues;

/** Standalone authority: the operation row is also its owner prefix/root mutex. */
#[Api(since: '1.0')]
final readonly class DatabaseOwnerAuthority implements OwnerAuthority, SqlConnectionParticipant
{
    public function __construct(private ConnectionScope $scope) {}

    public function connection(): Connection
    {
        return $this->scope->connection;
    }

    public function connectionName(): string
    {
        return $this->connection()->getName() ?? '';
    }

    public function snapshot(OwnerRef $owner): ?OwnerSnapshot
    {
        $this->scope->assertOutsideTransaction();
        $row = $this->query($owner)->first();
        if ($row === null) {
            return null;
        }
        $probeToken = RowValues::colNullableStr($row, 'probe_token');

        return new OwnerSnapshot(
            $owner,
            new DateTimeImmutable(RowValues::colStr($row, 'deadline')),
            controlVersions: ['operation' => RowValues::colInt($row, 'revision')],
            purpose: DemandPurpose::from(RowValues::colStr($row, 'purpose')),
            probeToken: $probeToken === null ? null : new Uuid($probeToken),
        );
    }

    public function validateSnapshot(OwnerRef $owner, OwnerSnapshot $snapshot): bool
    {
        return $owner->matchesIdentityAndToken($snapshot->owner) && isset($snapshot->controlVersions['operation']);
    }

    public function lockAndValidate(OwnerRef $owner, OwnerSnapshot $snapshot, LockedTransactionContext $transaction): LockedOwnerState
    {
        $this->scope->assertActive($transaction);
        $row = $this->query($owner)->lockForUpdate()->first();
        if ($row === null || ! $this->validateSnapshot($owner, $snapshot)
            || RowValues::colInt($row, 'revision') !== $snapshot->controlVersions['operation']
            || RowValues::colStr($row, 'purpose') !== $snapshot->purpose->value
            || RowValues::colNullableStr($row, 'probe_token') !== $snapshot->probeToken?->value
            || DatabaseClock::micros(new DateTimeImmutable(RowValues::colStr($row, 'deadline'))) !== DatabaseClock::micros($snapshot->deadlineAt)) {
            throw new AdmissionRejected('owner_lost');
        }

        return new LockedOwnerState($owner, $snapshot->purpose, $snapshot->deadlineAt, $snapshot->controlVersions, $snapshot->probeToken);
    }

    private function query(OwnerRef $owner): Builder
    {
        return $this->connection()->table(PackageSchema::tables()['operations'])->where('tenant_key', $owner->tenantKey->value)
            ->where('id', $owner->ownerId)->where('owner_type', $owner->ownerType)->where('owner_id', $owner->ownerId)
            ->where('owner_generation', $owner->generation)->where('owner_token', $owner->token())
            ->where('status', 'active')->whereNull('revoked_at')->whereRaw('deadline > clock_timestamp()');
    }
}
