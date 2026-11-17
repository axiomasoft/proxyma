<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use Packman\Attributes\Api;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Budget\Gcra;
use Proxyma\Domain\Policy\BudgetConstraint;
use Proxyma\Internal\Database\RowValues;
use stdClass;

/**
 * @phpstan-type BudgetSnapshot array{row: stdClass, tat: ?int, send: ?int, cooldown: ?int, gcra: Gcra, cap: int}
 */
#[Api(since: '1.0')]
final readonly class BudgetRepository
{
    public function __construct(private ConnectionScope $scope) {}

    /**
     * @param  list<stdClass>  $rows
     * @param  array<string, BudgetConstraint>  $constraints
     * @return array<string, BudgetSnapshot>
     */
    public function read(array $rows, array $constraints = []): array
    {
        $result = [];
        $t = PackageSchema::tables();
        $debts = [];
        $rowIds = array_values(array_unique(array_map(static fn (stdClass $row): string => RowValues::colStr($row, 'id'), $rows)));
        foreach (array_chunk($rowIds, 512) as $ids) {
            $batch = $this->scope->connection->table($t['budgets'])->whereIn('id', $ids)
                ->selectRaw('id, CASE WHEN isfinite(tat) THEN (extract(epoch FROM tat) * 1000000)::bigint END AS tat,
                    CASE WHEN isfinite(send_tat) THEN (extract(epoch FROM send_tat) * 1000000)::bigint END AS send,
                    CASE WHEN isfinite(cooldown_until) THEN (extract(epoch FROM cooldown_until) * 1000000)::bigint END AS cooldown,
                    tat::text AS tat_text, send_tat::text AS send_text')->get();
            foreach ($batch as $debt) {
                $debts[RowValues::colStr($debt, 'id')] = $debt;
            }
        }
        foreach ($rows as $row) {
            $rowId = RowValues::colStr($row, 'id');
            $debt = $debts[$rowId] ?? throw new AdmissionRejected('required_state_missing');
            if (RowValues::colNullableStr($debt, 'tat_text') === 'infinity' || RowValues::colNullableStr($debt, 'send_text') === 'infinity') {
                throw new AdmissionRejected('invalid_gcra_debt');
            }
            $scopeKey = RowValues::colStr($row, 'scope_key');
            $emissionIntervalUs = RowValues::colInt($row, 'emission_interval_us');
            $burst = RowValues::colInt($row, 'burst');
            $maxInflight = RowValues::colInt($row, 'max_inflight');
            $constraint = $constraints[$scopeKey] ?? new BudgetConstraint($scopeKey, $emissionIntervalUs, $burst, $maxInflight);
            $gcra = new Gcra(max($emissionIntervalUs, $constraint->emissionIntervalUs), min($burst, $constraint->burst));
            $result[$rowId] = [
                'row' => $row,
                'tat' => RowValues::colNullableInt($debt, 'tat'),
                'send' => RowValues::colNullableInt($debt, 'send'),
                'cooldown' => RowValues::colNullableInt($debt, 'cooldown'),
                'gcra' => $gcra,
                'cap' => min($maxInflight, $constraint->maxInflight),
            ];
        }

        return $result;
    }

    /**
     * Advisory only: no mutation or grant.
     *
     * @param  array<string, BudgetSnapshot>  $snapshots
     * @return array<string, int>
     */
    public function availability(array $snapshots, int $nowUs, int $retryUs = 100000): array
    {
        $result = [];
        foreach ($snapshots as $id => $snapshot) {
            $gcra = $snapshot['gcra'];
            $ready = max($gcra->eligibleAt($snapshot['tat'], $nowUs), $gcra->eligibleAt($snapshot['send'], $nowUs), $snapshot['cooldown'] ?? $nowUs);
            if (RowValues::colInt($snapshot['row'], 'inflight') >= $snapshot['cap']) {
                $ready = max($ready, $nowUs + $retryUs);
            }
            $result[$id] = $ready;
        }

        return $result;
    }

    /**
     * @param  array<string, BudgetSnapshot>  $snapshots
     * @return array<string, int>
     */
    public function planReserve(array $snapshots, int $slotUs, int $cost): array
    {
        $result = [];
        foreach ($snapshots as $id => $snapshot) {
            if (RowValues::colInt($snapshot['row'], 'inflight') >= $snapshot['cap']) {
                throw new AdmissionRejected('capacity_unavailable');
            }
            $result[$id] = $snapshot['gcra']->advance($snapshot['tat'], $slotUs, $cost);
        }

        return $result;
    }

    /**
     * @param  array<string, BudgetSnapshot>  $snapshots
     * @param  array<string, int>  $plan
     */
    public function applyReserve(array $snapshots, array $plan): void
    {
        foreach ($plan as $id => $tat) {
            $row = $snapshots[$id]['row'];
            $this->scope->connection->table(PackageSchema::tables()['budgets'])
                ->where('tenant_key', RowValues::colStr($row, 'tenant_key'))
                ->where('id', $id)
                ->update([
                    'tat' => DatabaseClock::instant($tat)->format('Y-m-d H:i:s.uP'),
                    'inflight' => RowValues::colInt($row, 'inflight') + 1,
                ]);
        }
    }

    /**
     * All scopes are validated before the first send-debt update.
     *
     * @param  array<string, BudgetSnapshot>  $snapshots
     * @return array<string, int>
     */
    public function planSend(array $snapshots, int $nowUs): array
    {
        $plan = [];
        foreach ($snapshots as $id => $snapshot) {
            $inflight = RowValues::colInt($snapshot['row'], 'inflight');
            if ($inflight < 1 || $inflight > $snapshot['cap']
                || ($snapshot['cooldown'] ?? $nowUs) > $nowUs
                || $snapshot['gcra']->eligibleAt($snapshot['send'], $nowUs) > $nowUs) {
                throw new AdmissionRejected('send_budget_unavailable');
            }
            $plan[$id] = $snapshot['gcra']->advance($snapshot['send'], $nowUs, 1);
        }

        return $plan;
    }

    /** @param array<string, int> $plan */
    public function applySend(array $plan): void
    {
        foreach ($plan as $id => $tat) {
            $this->scope->connection->table(PackageSchema::tables()['budgets'])->where('id', $id)
                ->update(['send_tat' => DatabaseClock::instant($tat)->format('Y-m-d H:i:s.uP')]);
        }
    }

    /**
     * @param  list<stdClass>  $references
     * @param  list<stdClass>  $lockedBudgets
     */
    public function release(array $references, array $lockedBudgets): void
    {
        $byId = [];
        foreach ($lockedBudgets as $lockedBudget) {
            $byId[RowValues::colStr($lockedBudget, 'id')] = $lockedBudget;
        }
        foreach ($references as $reference) {
            if (! in_array(RowValues::colStr($reference, 'state'), ['reserved', 'spent'], true)) {
                continue;
            }
            $budget = $byId[RowValues::colStr($reference, 'budget_id')] ?? throw new AdmissionRejected('reservation_budget_missing');
            if (RowValues::colInt($budget, 'inflight') < 1) {
                throw new AdmissionRejected('reservation_accounting_conflict');
            }
        }
        foreach ($references as $reference) {
            if (! in_array(RowValues::colStr($reference, 'state'), ['reserved', 'spent'], true)) {
                continue;
            }
            $budget = $byId[RowValues::colStr($reference, 'budget_id')];
            $this->scope->connection->table(PackageSchema::tables()['budgets'])
                ->where('tenant_key', RowValues::colStr($budget, 'tenant_key'))
                ->where('id', RowValues::colStr($budget, 'id'))
                ->decrement('inflight');
            $this->scope->connection->table(PackageSchema::tables()['reservations'])
                ->where('id', RowValues::colStr($reference, 'id'))
                ->update(['state' => 'released']);
        }
    }
}
