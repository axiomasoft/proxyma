<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Budget\Gcra;
use Proxyma\Domain\Policy\BudgetConstraint;
use stdClass;

final readonly class BudgetRepository
{
    public function __construct(private ConnectionScope $scope) {}

    /** @param list<stdClass> $rows
     * @return array<string, array{row: stdClass, tat: ?int, send: ?int, gcra: Gcra, cap: int}>
     */
    public function read(array $rows, array $constraints = []): array
    {
        $result = [];
        $t = PackageSchema::tables();
        foreach ($rows as $row) {
            $debt = $this->scope->connection->selectOne("SELECT CASE WHEN isfinite(tat) THEN (extract(epoch FROM tat) * 1000000)::bigint END AS tat,
                CASE WHEN isfinite(send_tat) THEN (extract(epoch FROM send_tat) * 1000000)::bigint END AS send,
                tat::text AS tat_text, send_tat::text AS send_text FROM {$t['budgets']} WHERE id = ?", [$row->id]);
            if ($debt->tat_text === 'infinity' || $debt->send_text === 'infinity') {
                throw new AdmissionRejected('invalid_gcra_debt');
            }
            $constraint = $constraints[$row->scope_key] ?? new BudgetConstraint($row->scope_key, (int) $row->emission_interval_us, (int) $row->burst, (int) $row->max_inflight);
            $gcra = new Gcra(max((int) $row->emission_interval_us, $constraint->emissionIntervalUs), min((int) $row->burst, $constraint->burst));
            $result[$row->id] = ['row' => $row, 'tat' => $debt->tat === null ? null : (int) $debt->tat,
                'send' => $debt->send === null ? null : (int) $debt->send, 'gcra' => $gcra, 'cap' => min((int) $row->max_inflight, $constraint->maxInflight)];
        }

        return $result;
    }

    /** Advisory only: no mutation or grant. @return array<string, int> */
    public function availability(array $snapshots, int $nowUs, int $retryUs = 100000): array
    {
        $result = [];
        foreach ($snapshots as $id => $snapshot) {
            $gcra = $snapshot['gcra'];
            $ready = max($gcra->eligibleAt($snapshot['tat'], $nowUs), $gcra->eligibleAt($snapshot['send'], $nowUs));
            if ((int) $snapshot['row']->inflight >= $snapshot['cap']) {
                $ready = max($ready, $nowUs + $retryUs);
            }
            $result[$id] = $ready;
        }

        return $result;
    }

    /** @return array<string, int> */
    public function planReserve(array $snapshots, int $slotUs, int $cost): array
    {
        $result = [];
        foreach ($snapshots as $id => $snapshot) {
            if ((int) $snapshot['row']->inflight >= $snapshot['cap']) {
                throw new AdmissionRejected('capacity_unavailable');
            }
            $result[$id] = $snapshot['gcra']->advance($snapshot['tat'], $slotUs, $cost);
        }

        return $result;
    }

    public function applyReserve(array $snapshots, array $plan): void
    {
        foreach ($plan as $id => $tat) {
            $row = $snapshots[$id]['row'];
            $this->scope->connection->table(PackageSchema::tables()['budgets'])->where('tenant_key', $row->tenant_key)->where('id', $id)
                ->update(['tat' => DatabaseClock::instant($tat)->format('Y-m-d H:i:s.uP'), 'inflight' => (int) $row->inflight + 1]);
        }
    }

    /** All scopes are validated before the first send-debt update. */
    public function planSend(array $snapshots, int $nowUs): array
    {
        $plan = [];
        foreach ($snapshots as $id => $snapshot) {
            if ((int) $snapshot['row']->inflight < 1 || (int) $snapshot['row']->inflight > $snapshot['cap']
                || $snapshot['gcra']->eligibleAt($snapshot['send'], $nowUs) > $nowUs) {
                throw new AdmissionRejected('send_budget_unavailable');
            }
            $plan[$id] = $snapshot['gcra']->advance($snapshot['send'], $nowUs, 1);
        }

        return $plan;
    }

    public function applySend(array $plan): void
    {
        foreach ($plan as $id => $tat) {
            $this->scope->connection->table(PackageSchema::tables()['budgets'])->where('id', $id)
                ->update(['send_tat' => DatabaseClock::instant($tat)->format('Y-m-d H:i:s.uP')]);
        }
    }

    public function release(array $references, array $lockedBudgets): void
    {
        $byId = array_column($lockedBudgets, null, 'id');
        foreach ($references as $reference) {
            if (! in_array($reference->state, ['reserved', 'spent'], true)) {
                continue;
            }
            $budget = $byId[$reference->budget_id] ?? throw new AdmissionRejected('reservation_budget_missing');
            if ((int) $budget->inflight < 1) {
                throw new AdmissionRejected('reservation_accounting_conflict');
            }
        }
        foreach ($references as $reference) {
            if (! in_array($reference->state, ['reserved', 'spent'], true)) {
                continue;
            }
            $budget = $byId[$reference->budget_id];
            $this->scope->connection->table(PackageSchema::tables()['budgets'])->where('tenant_key', $budget->tenant_key)->where('id', $budget->id)->decrement('inflight');
            $this->scope->connection->table(PackageSchema::tables()['reservations'])->where('id', $reference->id)->update(['state' => 'released']);
        }
    }
}
