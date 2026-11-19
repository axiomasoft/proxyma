<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Admission;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Policy\EffectivePolicy;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Internal\Database\RowValues;

/** Includes absent/new bindings through the complete, bounded policy-head set. */
#[Internal]
final class PolicyAuthoritySnapshot
{
    /** @return array{heads: list<array{0: string, 1: string, 2: int}>, versions: array<string, int>} */
    public static function read(Connection $db, EffectivePolicy $policy): array
    {
        $t = PackageSchema::tables($db);
        $heads = $db->table($t['policy_heads'])->whereIn('tenant_key', array_unique([$policy->tenantKey->value, TenantKey::SYSTEM]))
            ->orderBy('scope_key')->orderBy('tenant_key')->limit(1025)->get()->all();
        if (count($heads) > 1024) {
            throw new AdmissionRejected('policy_state_bound');
        }
        $versions = [];
        $headEntries = [];
        foreach ($heads as $head) {
            $tenantKey = RowValues::colStr($head, 'tenant_key');
            $scopeKey = RowValues::colStr($head, 'scope_key');
            $revision = RowValues::colInt($head, 'revision');
            $versions['head:'.$tenantKey.':'.$scopeKey] = $revision;
            $headEntries[] = [$tenantKey, $scopeKey, $revision];
        }
        $bindings = $db->table($t['bindings'])->where('tenant_key', $policy->tenantKey->value)
            ->where(function (Builder $q) use ($policy): void {
                foreach ($policy->subjectChain->ordered as $subject) {
                    $q->orWhere(fn (Builder $p): Builder => $p->where('subject_type', $subject->type)->where('subject_id', $subject->id));
                }
            })->whereNull('retired_at')->orderBy('id')->limit(1025)->get()->all();
        if (count($bindings) > 1024) {
            throw new AdmissionRejected('binding_state_bound');
        }
        foreach ($bindings as $binding) {
            $versions['binding:'.RowValues::colStr($binding, 'id')] = RowValues::colInt($binding, 'revision');
        }
        ksort($versions, SORT_STRING);

        return ['heads' => $headEntries, 'versions' => $versions];
    }

    /** @return array{heads: list<array{0: string, 1: string, 2: int}>, versions: array<string, int>} */
    public static function capture(Connection $db, EffectivePolicy $policy): array
    {
        $state = self::read($db, $policy);
        $expected = array_filter($policy->authorityVersions, static fn (string $key): bool => str_starts_with($key, 'head:'), ARRAY_FILTER_USE_KEY);
        $actual = array_filter($state['versions'], static fn (string $key): bool => str_starts_with($key, 'head:'), ARRAY_FILTER_USE_KEY);
        ksort($expected, SORT_STRING);
        if ($policy->authorityVersions !== [] && $expected !== $actual) {
            throw new AdmissionRejected('policy_snapshot_stale');
        }

        return $state;
    }
}
