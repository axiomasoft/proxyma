<?php

declare(strict_types=1);

namespace Proxyma\Application\Diagnostics;

use DateTimeImmutable;
use LogicException;
use Packman\Attributes\Api;
use Proxyma\Contracts\Policy\PolicyResolver;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Domain\Routing\Candidate;
use Proxyma\Infrastructure\Database\CandidateSnapshotQuery;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Database\DatabaseClock;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Internal\Database\RowValues;

#[Api(since: '1.0')]
final readonly class AdmissionDiagnosis
{
    public function __construct(private ConnectionScope $scope, private PolicyResolver $policies, private CandidateSnapshotQuery $candidates) {}

    /** @return array<string, mixed> */
    public function explain(SubjectChain $subjects, Uuid $profile, Uuid $target, string $traffic = 'default'): array
    {
        $this->scope->assertOutsideTransaction();
        $explanation = $this->policies->explain($subjects, $target, $traffic);
        $policy = $explanation->effectivePolicy ?? throw new LogicException('Effective policy explanation required.');
        $snapshot = $this->candidates->readOnly($subjects->tenant, $profile, $target, $policy);
        $keys = array_values(array_unique(array_merge([], ...array_map(static fn (Candidate $c): array => $c->budgetScopes, $snapshot->candidates))));
        $t = PackageSchema::tables();
        $budgets = $this->scope->connection->table($t['budgets'])
            ->whereIn('tenant_key', array_unique([$subjects->tenant->value, TenantKey::SYSTEM]))->whereIn('scope_key', $keys)->get();
        $windows = $this->scope->connection->table($t['quota_windows'])->whereIn('budget_id', $budgets->pluck('id')->all())
            ->whereRaw('starts_at<=clock_timestamp() AND ends_at>clock_timestamp() AND reserved+spent>=quota_limit')->get();
        $timing = function (Candidate $candidate) use ($budgets, $windows, $snapshot, $policy): ?string {
            if ($candidate->exclusions !== []) {
                return null;
            }
            $next = DatabaseClock::micros($snapshot->observedAt);
            foreach ($budgets->whereIn('scope_key', $candidate->budgetScopes) as $budget) {
                $scopeKey = RowValues::colStr($budget, 'scope_key');
                $constraint = $policy->budgets[$scopeKey] ?? null;
                if (RowValues::colInt($budget, 'inflight') >= min(RowValues::colInt($budget, 'max_inflight'), $constraint->maxInflight ?? PHP_INT_MAX)) {
                    return null;
                }
                $interval = max(RowValues::colInt($budget, 'emission_interval_us'), $constraint->emissionIntervalUs ?? 0);
                $burst = min(RowValues::colInt($budget, 'burst'), $constraint->burst ?? PHP_INT_MAX);
                $allowance = $interval > intdiv(PHP_INT_MAX, max(1, $burst - 1)) ? PHP_INT_MAX : $interval * ($burst - 1);
                foreach (['cooldown_until', 'tat', 'send_tat'] as $column) {
                    $ts = RowValues::colNullableStr($budget, $column);
                    if (in_array($ts, ['-infinity', 'infinity', null], true)) {
                        continue;
                    }
                    $bound = DatabaseClock::micros(new DateTimeImmutable(RowValues::str($ts)));
                    if ($bound > $next) {
                        $next = max($next, $column === 'cooldown_until' ? $bound : $bound - $allowance);
                    }
                }
                foreach ($windows->where('budget_id', RowValues::colStr($budget, 'id')) as $window) {
                    $next = max($next, DatabaseClock::micros(new DateTimeImmutable(RowValues::colStr($window, 'ends_at'))));
                }
            }

            return DatabaseClock::instant($next)->format('Y-m-d\TH:i:s.uP');
        };
        $fields = [];
        foreach ($explanation->fields as $key => $field) {
            // Only the built-in numeric/boolean policy fields have an output schema.
            if (! in_array($key, ['timeout.connect_ms', 'timeout.read_ms', 'timeout.total_ms', 'retry.max_attempts', 'http.max_redirects', 'route.allow_direct'], true)) {
                continue;
            }
            $fields[$key] = ['value' => $field->sensitive ? null : (is_int($field->effectiveValue) || is_bool($field->effectiveValue) ? $field->effectiveValue : null),
                'revision' => $field->sourceRevision, 'clamped' => $field->clamped, 'disabled' => $field->disabled];
        }

        return [
            'advisory' => true, 'revalidate_on_send' => true, 'as_of' => $snapshot->observedAt->format('Y-m-d\TH:i:s.uP'),
            'policy_revision' => $snapshot->policyRevision, 'policy_hash' => $policy->policyHash->hex,
            'profile_revision' => $snapshot->profile?->revision, 'target_revision' => $snapshot->target?->revision,
            'holds' => $snapshot->holds, 'fields' => $fields,
            'candidates' => array_map(static fn (Candidate $candidate): array => [
                'route_id' => $candidate->routeId->value, 'eligible' => $candidate->eligible(), 'budget_ready' => $candidate->budgetReady,
                'next_eligible_at' => $timing($candidate), 'next_eligible_is_advisory' => true,
                'exclusions' => $candidate->exclusions, 'health_epochs' => $candidate->healthEpochs,
                'health_versions' => $candidate->healthVersions,
                // Budget scope keys contain origins; return only revision values.
                'budget_revisions' => array_values($candidate->budgetRevisions),
            ], $snapshot->candidates),
        ];
    }
}
