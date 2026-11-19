<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use DateTimeImmutable;
use Packman\Attributes\Api;
use Proxyma\Application\Admission\AuthorityLocker;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Admission\AuthorityLockTier;
use Proxyma\Domain\Admission\ReservationInput;
use Proxyma\Domain\Budget\Gcra;
use Proxyma\Domain\Execution\ContentHash;
use Proxyma\Domain\Execution\DemandTicket;
use Proxyma\Domain\Execution\LockedOwnerState;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\PermitEvidence;
use Proxyma\Domain\Execution\PermitState;
use Proxyma\Domain\Execution\ScheduledPermit;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\HealthEpochMap;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Internal\Database\RowValues;
use stdClass;

#[Api(since: '1.0')]
final readonly class PermitRepository
{
    public function __construct(private ConnectionScope $scope, private AuthorityLocker $locks) {}

    public function existing(TenantKey $tenant, string $demandId, LockedTransactionContext $tx): ?stdClass
    {
        $row = PackageSchema::query($this->scope->connection, 'permits')->where('tenant_key', $tenant->value)->where('demand_id', $demandId)->first();

        return $row === null ? null : $this->locks->rows(AuthorityLockTier::Permit, $tenant, [RowValues::colStr($row, 'id')], $tx)[0];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<stdClass>  $budgets
     */
    public function insert(array $values, array $budgets): stdClass
    {
        $t = PackageSchema::tables($this->scope->connection);
        $id = Uuid::random()->value;
        $values['id'] = $id;
        $this->scope->connection->table($t['permits'])->insert($values);
        foreach ($budgets as $budget) {
            $this->scope->connection->table($t['reservations'])->insert(['tenant_key' => $values['tenant_key'], 'permit_id' => $id,
                'budget_tenant_key' => RowValues::colStr($budget, 'tenant_key'), 'budget_id' => RowValues::colStr($budget, 'id'), 'cost' => $values['cost'], 'state' => 'reserved']);
        }

        return (object) [...$values, 'state' => 'reserved', 'authorized_at' => null];
    }

    /** @return list<stdClass> */
    public function references(string $id): array
    {
        return array_values(PackageSchema::query($this->scope->connection, 'reservations')->where('permit_id', $id)->orderBy('budget_id')->get()->all());
    }

    /**
     * The root, catalog, budgets, demand and permit are already locked in protocol order.
     *
     * @param  array<string, array{row: stdClass, tat: ?int, send: ?int, cooldown: ?int, gcra: Gcra, cap: int}>  $snapshots
     * @param  array<string, mixed>  $safeSnapshot
     * @param  array<string, array{id: string, budget_id: string, owner: string, kind: string, policy: string, limit: int, starts: string, ends: string, reserved: int, spent: int}>  $quotaPlan
     */
    public function authorize(stdClass $permit, ReservationInput $input, LockedOwnerState $owner, array $snapshots,
        array $safeSnapshot, array $quotaPlan = []): stdClass
    {
        $t = PackageSchema::tables($this->scope->connection);
        $db = $this->scope->connection;
        $required = intdiv($input->request->timeouts->totalWithCleanupMarginNs(), 1000);
        $ownerRef = $input->operation->owner;
        $deadline = min($owner->deadlineAt, $input->ownerSnapshot->deadlineAt, $input->request->deadlineAt);
        $probeUntil = RowValues::nullableStr($safeSnapshot['probe_until'] ?? null);
        if ($probeUntil !== null) {
            $deadline = min($deadline, new DateTimeImmutable($probeUntil));
        }
        $checks = [];
        $permitId = RowValues::colStr($permit, 'id');
        $bindings = [$permitId, $ownerRef->tenantKey->value, $input->request->fingerprint()->hex,
            $input->request->operationId->value, $input->request->physicalAttemptId->value, $input->route->id->value,
            $ownerRef->ownerType, $ownerRef->ownerId, hash('sha256', $ownerRef->token()), $ownerRef->generation,
            $required, $deadline->format('Y-m-d H:i:s.uP'), $required, $required, $required, count($snapshots)];
        foreach ($snapshots as $id => $snapshot) {
            $checks[] = "EXISTS (SELECT 1 FROM {$t['budgets']} b JOIN {$t['reservations']} r ON r.budget_id=b.id
                AND r.budget_tenant_key=b.tenant_key WHERE r.permit_id=p.id AND b.id=? AND b.revision=?
                AND r.state='reserved' AND r.cost=1 AND r.quota_window_id IS NULL AND b.inflight BETWEEN 1 AND ? AND b.cooldown_until<=n.t
                AND (b.send_tat='-infinity'::timestamptz OR (isfinite(b.send_tat) AND
                    extract(epoch FROM b.send_tat)*1000000 <= extract(epoch FROM n.t)*1000000 + ?::bigint)))";
            $bindings[] = $id;
            $bindings[] = RowValues::colInt($snapshot['row'], 'revision');
            $bindings[] = $snapshot['cap'];
            $bindings[] = $snapshot['gcra']->toleranceUs;
        }
        $checks[] = "EXISTS (SELECT 1 FROM {$t['route_profile_members']} m WHERE m.tenant_key=p.tenant_key
            AND m.profile_id=o.profile_id AND m.route_id=p.route_id AND m.route_owner_tenant_key=?)";
        $bindings[] = $input->route->tenantKey->value;
        foreach (RowValues::assoc($safeSnapshot['versions'] ?? []) as $key => $revision) {
            [$kind, $id] = array_pad(explode(':', $key, 2), 2, '');
            $name = match ($kind) {
                'profile' => 'route_profiles', 'route' => 'routes', 'endpoint' => 'endpoints', 'target' => 'targets',
                'route_health', 'route_target_health', 'target_health' => $kind, 'operation' => 'operations', default => null,
            };
            if ($kind === 'head') {
                continue;
            }
            if ($name !== null) {
                $column = str_ends_with($name, 'health') ? 'state_version' : 'revision';
                $retired = in_array($name, ['route_profiles', 'routes', 'endpoints', 'targets'], true) ? ' AND v.retired_at IS NULL' : '';
                $checks[] = "EXISTS (SELECT 1 FROM {$t[$name]} v WHERE v.id=? AND v.{$column}=?{$retired})";
                $bindings[] = $id;
                $bindings[] = RowValues::int($revision);
            }
        }
        $rawHeads = $input->policyState['heads'] ?? [];
        if (is_array($rawHeads)) {
            foreach ($rawHeads as $head) {
                if (! is_array($head) || count($head) !== 3) {
                    continue;
                }
                $checks[] = "EXISTS (SELECT 1 FROM {$t['policy_heads']} h WHERE h.tenant_key=? AND h.scope_key=? AND h.revision=?)";
                $bindings[] = RowValues::str($head[0]);
                $bindings[] = RowValues::str($head[1]);
                $bindings[] = RowValues::int($head[2]);
            }
        }
        foreach (RowValues::assoc($safeSnapshot['epochs'] ?? []) as $key => $epoch) {
            [$name, $id] = explode(':', $key, 2);
            if (! in_array($name, ['route_health', 'route_target_health', 'target_health'], true)) {
                throw new AdmissionRejected('invalid_health_evidence');
            }
            $checks[] = "EXISTS (SELECT 1 FROM {$t[$name]} h WHERE h.id=? AND h.health_epoch=?)";
            $bindings[] = $id;
            $bindings[] = RowValues::int($epoch);
        }
        foreach (['route_health', 'route_target_health', 'target_health'] as $name) {
            $checks[] = "NOT EXISTS (SELECT 1 FROM {$t[$name]} h WHERE ".($name === 'target_health' ? 'h.target_id=p.target_id' : 'h.route_id=p.route_id')
                .($name === 'route_target_health' ? ' AND h.target_id=p.target_id' : '')
                ." AND (h.state NOT IN ('closed','half_open') OR (h.state='half_open' AND
                    (h.probe_token IS DISTINCT FROM ?::uuid OR h.probe_until <= n.t))))";
            $bindings[] = $owner->probeToken?->value;
        }
        $checks[] = "(SELECT count(*) FROM {$t['quota_reservations']} q WHERE q.permit_id=p.id)=?";
        $bindings[] = count($quotaPlan);
        foreach ($quotaPlan as $window) {
            $checks[] = "EXISTS (SELECT 1 FROM {$t['quota_reservations']} r JOIN {$t['quota_windows']} q ON q.id=r.window_id
                WHERE r.permit_id=p.id AND r.window_id=? AND r.state='reserved' AND r.cost=1
                AND q.starts_at<=n.t AND q.ends_at>=n.t+(?::bigint*interval '1 microsecond')
                AND q.reserved>=1 AND q.spent<=?::bigint-q.reserved AND q.spent<=q.quota_limit-q.reserved)";
            $bindings[] = $window['id'];
            $bindings[] = $required;
            $bindings[] = $window['limit'];
        }
        $extra = implode(' AND ', $checks);
        $row = $db->selectOne("WITH instant AS MATERIALIZED (SELECT clock_timestamp() AS t)
            UPDATE {$t['permits']} p SET state='authorized', authorized_at=n.t, claim_id=gen_random_uuid()
            FROM instant n, {$t['operations']} o, {$t['demands']} d
            WHERE p.id=? AND p.tenant_key=? AND p.state='reserved' AND p.wire_fingerprint=?
                AND p.operation_id=? AND p.physical_attempt_id=? AND p.route_id=? AND p.cost=1
                AND o.id=p.operation_id AND o.tenant_key=p.tenant_key AND o.status='active' AND o.revoked_at IS NULL
                AND o.owner_type=? AND o.owner_id=? AND encode(sha256(convert_to(o.owner_token, 'UTF8')), 'hex')=? AND o.owner_generation=?
                AND o.authorized_attempts < o.max_physical_attempts
                AND o.deadline >= n.t + (?::bigint * interval '1 microsecond')
                AND ?::timestamptz >= n.t + (?::bigint * interval '1 microsecond')
                AND d.id=p.demand_id AND d.tenant_key=p.tenant_key AND d.state='admitted'
                AND d.physical_attempt_id=p.physical_attempt_id AND d.wire_fingerprint=p.wire_fingerprint
                AND d.deadline >= n.t + (?::bigint * interval '1 microsecond') AND d.expires_at > n.t
                AND p.not_before <= n.t AND p.expires_at >= n.t + (?::bigint * interval '1 microsecond')
                AND (SELECT count(*) FROM {$t['reservations']} r WHERE r.permit_id=p.id)=?
                AND {$extra}
            RETURNING p.authorized_at::text AS authorized_at, p.expires_at::text AS expires_at, p.claim_id::text AS claim_id", $bindings);
        if (! $row instanceof stdClass) {
            throw new AdmissionRejected('final_send_cas_denied');
        }
        $db->table($t['operations'])->where('id', RowValues::colStr($permit, 'operation_id'))->increment('authorized_attempts');
        $db->table($t['reservations'])->where('permit_id', $permitId)->update(['state' => 'spent']);
        $db->table($t['attempts'])->insert(['tenant_key' => RowValues::colStr($permit, 'tenant_key'), 'operation_id' => RowValues::colStr($permit, 'operation_id'),
            'permit_id' => $permitId, 'physical_attempt_id' => RowValues::colStr($permit, 'physical_attempt_id'), 'wire_fingerprint' => RowValues::colStr($permit, 'wire_fingerprint'),
            'status' => 'authorized', 'authorized_at' => RowValues::colStr($row, 'authorized_at'), 'snapshot' => json_encode($safeSnapshot, JSON_THROW_ON_ERROR)]);

        return $row;
    }

    public function result(stdClass $row, DemandTicket $ticket): ScheduledPermit
    {
        $evidence = RowValues::jsonAssoc(RowValues::colStr($row, 'reservation_evidence'));
        $versions = [];
        foreach (RowValues::assoc($evidence['versions'] ?? []) as $k => $v) {
            $versions[$k] = RowValues::int($v);
        }
        $epochs = [];
        foreach (RowValues::assoc($evidence['epochs'] ?? []) as $k => $v) {
            $epochs[$k] = RowValues::int($v);
        }
        $authorizedAt = RowValues::colNullableStr($row, 'authorized_at');

        return new ScheduledPermit(
            new Uuid(RowValues::colStr($row, 'id')),
            $ticket,
            new DateTimeImmutable(RowValues::colStr($row, 'not_before')),
            new DateTimeImmutable(RowValues::colStr($row, 'expires_at')),
            new PermitEvidence($versions, new HealthEpochMap($epochs), new ContentHash(RowValues::colStr($row, 'wire_fingerprint'))),
            PermitState::from(RowValues::colStr($row, 'state')),
            $authorizedAt === null ? null : new DateTimeImmutable($authorizedAt),
        );
    }
}
