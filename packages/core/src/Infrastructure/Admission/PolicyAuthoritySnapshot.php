<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Admission;

use Illuminate\Database\Connection;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Policy\EffectivePolicy;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\PackageSchema;

/** Includes absent/new bindings through the complete, bounded policy-head set. */
final class PolicyAuthoritySnapshot
{
    public static function read(Connection $db, EffectivePolicy $policy): array
    {
        $t = PackageSchema::tables();
        $heads = $db->table($t['policy_heads'])->whereIn('tenant_key', array_unique([$policy->tenantKey->value, TenantKey::SYSTEM]))
            ->orderBy('scope_key')->orderBy('tenant_key')->limit(1025)->get()->all();
        if (count($heads) > 1024) {
            throw new AdmissionRejected('policy_state_bound');
        }
        $versions = [];
        foreach ($heads as $head) {
            $versions['head:'.$head->tenant_key.':'.$head->scope_key] = (int) $head->revision;
        }
        $bindings = $db->table($t['bindings'])->where('tenant_key', $policy->tenantKey->value)
            ->where(function ($q) use ($policy): void {
                foreach ($policy->subjectChain->ordered as $subject) {
                    $q->orWhere(fn ($p) => $p->where('subject_type', $subject->type)->where('subject_id', $subject->id));
                }
            })->whereNull('retired_at')->orderBy('id')->limit(1025)->get()->all();
        if (count($bindings) > 1024) {
            throw new AdmissionRejected('binding_state_bound');
        }
        foreach ($bindings as $binding) {
            $versions['binding:'.$binding->id] = (int) $binding->revision;
        }
        ksort($versions, SORT_STRING);

        return ['heads' => array_map(static fn ($row) => [$row->tenant_key, $row->scope_key, (int) $row->revision], $heads), 'versions' => $versions];
    }

    public static function capture(Connection $db, EffectivePolicy $policy): array
    {
        $state = self::read($db, $policy);
        $expected = array_filter($policy->authorityVersions, static fn ($key) => str_starts_with($key, 'head:'), ARRAY_FILTER_USE_KEY);
        $actual = array_filter($state['versions'], static fn ($key) => str_starts_with($key, 'head:'), ARRAY_FILTER_USE_KEY);
        ksort($expected, SORT_STRING);
        if ($policy->authorityVersions !== [] && $expected !== $actual) {
            throw new AdmissionRejected('policy_snapshot_stale');
        }

        return $state;
    }
}
