<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Proxyma\Domain\Execution\ContentHash;
use Proxyma\Domain\Execution\RequestTimeoutPolicy;
use Proxyma\Domain\Execution\SafeUrl;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Policy\EffectivePolicy;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\PackageSchema;

function selectionPolicy(TenantKey $tenant, Uuid $profile, Uuid $target, string $strategy = 'uniform_random', bool $allowDirect = true, array $budgets = [], array $denied = [], array $layerRevisions = [], string $trafficKey = 'default'): EffectivePolicy
{
    return new EffectivePolicy(
        tenantKey: $tenant, subjectChain: new SubjectChain(new SubjectRef($tenant, 'document', 'fixture')),
        targetId: $target, destinationPolicyKey: 'default', trafficKey: $trafficKey, routeProfileId: $profile,
        routeProfileKey: 'default', routeProfileRevision: null, allowedProfileKeys: null,
        timeouts: new RequestTimeoutPolicy(1000, 3000, 5000, maxAttempts: 3, maxDistinctRoutes: 3), retryDelaysDisabled: false,
        allowDirectRoute: $allowDirect, strategyKey: $strategy, outcomePolicyKey: 'default', deniedRoutes: $denied,
        allowedDestinations: null, budgets: $budgets, values: [], disabledSettings: [], layerRevisions: $layerRevisions,
        appliedLayers: [], explanations: [], effectiveRevision: 1, policyHash: ContentHash::of('fixture'),
    );
}

function selectionRequest(Uuid $target): WireRequest
{
    return new WireRequest(Uuid::random(), Uuid::random(), $target, 1, 'GET', new SafeUrl('https://example.test/path'),
        new RequestTimeoutPolicy(1000, 3000, 5000), new DateTimeImmutable('+1 hour'));
}

function selectionHealth(Connection $db, TenantKey $tenant): void
{
    $t = PackageSchema::tables();
    foreach (['route_health', 'route_target_health', 'profile_health', 'target_health'] as $name) {
        $values = ['state' => 'closed'];
        if ($name === 'target_health') {
            $values['availability'] = 'available';
        }
        $db->table($t[$name])->where('tenant_key', $tenant->value)->update($values);
    }
}

/** @return list<Uuid> */
function selectionAddRoutes(Connection $db, TenantKey $tenant, Uuid $profile, Uuid $target, int $count): array
{
    $t = PackageSchema::tables();
    $routes = [];
    $members = [];
    $health = [];
    $pairs = [];
    $ids = [];
    $position = (int) $db->table($t['route_profile_members'])->where('tenant_key', $tenant->value)->where('profile_id', $profile->value)->count();
    for ($i = 0; $i < $count; $i++) {
        $id = Uuid::random();
        $ids[] = $id;
        $routes[] = ['id' => $id->value, 'tenant_key' => $tenant->value, 'route_key' => 'fixture-'.str_replace('-', '', $id->value),
            'kind' => 'direct', 'endpoint_id' => null, 'enabled' => true, 'egress_key' => 'pool:shared', 'conservative_pool' => true];
        $members[] = ['tenant_key' => $tenant->value, 'profile_id' => $profile->value, 'route_id' => $id->value, 'position' => $position + $i];
        $health[] = ['tenant_key' => $tenant->value, 'route_id' => $id->value, 'state' => 'closed'];
        $pairs[] = ['tenant_key' => $tenant->value, 'route_id' => $id->value, 'target_id' => $target->value, 'state' => 'closed'];
    }
    if ($routes !== []) {
        $db->table($t['routes'])->insert($routes);
        $db->table($t['route_profile_members'])->insert($members);
        $db->table($t['route_health'])->insert($health);
        $db->table($t['route_target_health'])->insert($pairs);
    }

    return $ids;
}
