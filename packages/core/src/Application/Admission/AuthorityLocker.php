<?php

declare(strict_types=1);

namespace Proxyma\Application\Admission;

use Illuminate\Database\DatabaseTransactionRecord;
use LogicException;
use Proxyma\Contracts\LockedAdmissionGuard;
use Proxyma\Contracts\OwnerAuthority;
use Proxyma\Contracts\SqlConnectionParticipant;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Admission\AuthorityLockTier;
use Proxyma\Domain\Admission\LockPosition;
use Proxyma\Domain\Execution\AdmissionLockTier;
use Proxyma\Domain\Execution\ExecutionBlocked;
use Proxyma\Domain\Execution\LockedOwnerState;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\OwnerRef;
use Proxyma\Domain\Execution\OwnerSnapshot;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Database\PackageSchema;
use stdClass;
use WeakMap;

final class AuthorityLocker
{
    /** @var WeakMap<DatabaseTransactionRecord, LockPosition> */
    private WeakMap $positions;

    public function __construct(private readonly ConnectionScope $scope)
    {
        $this->positions = new WeakMap;
    }

    public function enter(AuthorityLockTier $tier, string $stableKey, LockedTransactionContext $transaction): void
    {
        $this->scope->assertActive($transaction);
        $root = $this->scope->root();
        $this->positions[$root] ??= new LockPosition;
        $this->positions[$root]->enter($tier, $stableKey);
    }

    public function participant(SqlConnectionParticipant $participant, AuthorityLockTier $tier, string $key, LockedTransactionContext $transaction): void
    {
        $this->scope->assertSame($participant->connection());
        $this->enter($tier, $key, $transaction);
    }

    public function owner(OwnerAuthority $authority, OwnerRef $owner, OwnerSnapshot $snapshot, LockedTransactionContext $transaction): LockedOwnerState
    {
        if (! $authority instanceof SqlConnectionParticipant || $authority->connectionName() !== $transaction->connectionName) {
            throw new LogicException('OwnerAuthority must declare its local SQL Connection.');
        }
        $this->participant($authority, AuthorityLockTier::Owner, $owner->ownerId, $transaction);
        if (! $owner->matchesIdentityAndToken($snapshot->owner) || ! $authority->validateSnapshot($owner, $snapshot)) {
            throw new AdmissionRejected('owner_snapshot_stale');
        }
        $state = $authority->lockAndValidate($owner, $snapshot, $transaction);
        if ($state instanceof ExecutionBlocked || ! $state->owner->matchesIdentityAndToken($owner)) {
            throw new AdmissionRejected('owner_lost');
        }
        $now = new \DateTimeImmutable($this->scope->connection->selectOne('SELECT clock_timestamp() AS now')->now);
        if (min($snapshot->deadlineAt, $state->deadlineAt) <= $now) {
            throw new AdmissionRejected('owner_deadline');
        }

        return new LockedOwnerState($state->owner, $state->purpose, min($snapshot->deadlineAt, $state->deadlineAt),
            $state->pinnedVersions, $state->probeToken, $state->adapterHealthId, $state->adapterHealthEpoch);
    }

    /**
     * Locks only existing materialized rows; callers validate the returned state before mutation.
     *
     * @param  list<string>  $keys
     * @return list<stdClass>
     */
    public function rows(AuthorityLockTier $tier, TenantKey $tenant, array $keys, LockedTransactionContext $transaction): array
    {
        [$name, $column] = match ($tier) {
            AuthorityLockTier::Operation => ['operations', 'id'],
            AuthorityLockTier::Policy => ['policy_heads', 'scope_key'],
            AuthorityLockTier::Profile => ['route_profiles', 'id'],
            AuthorityLockTier::Endpoint => ['endpoints', 'id'],
            AuthorityLockTier::Route => ['routes', 'id'],
            AuthorityLockTier::RouteHealth => ['route_health', 'id'],
            AuthorityLockTier::RouteTargetHealth => ['route_target_health', 'id'],
            AuthorityLockTier::Target => ['targets', 'id'],
            AuthorityLockTier::TargetHealth => ['target_health', 'id'],
            AuthorityLockTier::Budget => ['budgets', 'scope_key'],
            AuthorityLockTier::Demand => ['demands', 'id'],
            AuthorityLockTier::Permit => ['permits', 'id'],
            AuthorityLockTier::Attempt => ['attempts', 'id'],
            AuthorityLockTier::Observation => ['observations', 'id'],
            AuthorityLockTier::Application => ['observation_applications', 'id'],
            default => throw new LogicException('External prefix and adapter tiers require their declared SQL participant.'),
        };
        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);
        $rows = [];
        foreach ($keys as $key) {
            $this->enter($tier, $key."\0".$tenant->value, $transaction);
            $row = $this->scope->connection->table(PackageSchema::tables()[$name])
                ->where('tenant_key', $tenant->value)->where($column, $key)->lockForUpdate()->first();
            if ($row === null) {
                throw new AdmissionRejected('required_state_missing');
            }
            $rows[] = $row;
        }

        return $rows;
    }

    public function guard(string $id, LockedAdmissionGuard $guard, WireRequest $request, LockedOwnerState $owner, LockedTransactionContext $transaction): void
    {
        if ($guard->tier() !== AdmissionLockTier::AdapterGuard || ! $guard instanceof SqlConnectionParticipant) {
            throw new LogicException('Project adapter guard must declare the adapter_guard tier and SQL Connection.');
        }
        $this->participant($guard, AuthorityLockTier::AdapterGuard, $id, $transaction);
        if ($guard->evaluateLocked($request, $owner, $transaction) !== null) {
            throw new AdmissionRejected('adapter_guard_denied');
        }
    }

    public function operation(Uuid $id, OwnerRef $owner, LockedTransactionContext $transaction): stdClass
    {
        $row = $this->rows(AuthorityLockTier::Operation, $owner->tenantKey, [$id->value], $transaction)[0];
        $now = new \DateTimeImmutable($this->scope->connection->selectOne('SELECT clock_timestamp() AS now')->now);
        if ($row->owner_type !== $owner->ownerType || $row->owner_id !== $owner->ownerId
            || ! hash_equals($row->owner_token, $owner->token()) || (int) $row->owner_generation !== $owner->generation
            || $row->status !== 'active' || $row->revoked_at !== null || new \DateTimeImmutable($row->deadline) <= $now) {
            throw new AdmissionRejected('operation_owner_lost');
        }

        return $row;
    }
}
