<?php

declare(strict_types=1);

use Proxyma\Application\Policy\CoreSettings;
use Proxyma\Application\Policy\PolicyResolver;
use Proxyma\Application\Policy\SettingRegistry;
use Proxyma\Contracts\Policy\PolicyCommands;
use Proxyma\Contracts\Policy\PolicyResolver as PolicyResolverContract;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\EgressIdentity;
use Proxyma\Domain\Catalog\ProxyProtocol;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Catalog\UpsertEndpointCommand;
use Proxyma\Domain\Catalog\UpsertRouteCommand;
use Proxyma\Domain\Catalog\UpsertRouteProfileCommand;
use Proxyma\Domain\Catalog\UpsertTargetCommand;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\PolicyChanged;
use Proxyma\Domain\Policy\PolicyScope;
use Proxyma\Domain\Policy\SettingDescriptor;
use Proxyma\Domain\Policy\SettingMerge;
use Proxyma\Domain\Policy\SettingType;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Domain\Policy\UpdatePolicyCommand;
use Proxyma\Infrastructure\Cache\PolicyResolutionCache;
use Proxyma\Tests\Support\CatalogTestCase;

require_once __DIR__.'/../Support/CatalogTestCase.php';

uses(CatalogTestCase::class)->group('pg');

/**
 * @param  array<string, array<string, mixed>>  $operations
 */
function cacheTestPatchJson(array $operations): string
{
    return json_encode([
        'schema_version' => 1,
        'operations' => (object) $operations,
    ], JSON_THROW_ON_ERROR);
}

it('изолирует кэш между арендаторами с одинаковыми opaque ID субъектов и переиспользует один воркер без утечек контекста', function (): void {
    $tenantAcme = new TenantKey('acme');
    $tenantGlobex = new TenantKey('globex');

    $policyCommands = $this->app->make(PolicyCommands::class);
    $cache = $this->app->make(PolicyResolutionCache::class);
    $cache->flush();

    /** @var PolicyResolver $workerResolver */
    $workerResolver = $this->app->make(PolicyResolverContract::class);

    // Настраиваем арендатора acme с субъектом crawler:shared-42 (connect_ms = 1200)
    $this->useTenant($tenantAcme);
    $this->commands(true);

    $targetAcme = Uuid::random();
    $this->commands()->upsertTarget(new UpsertTargetCommand(
        targetId: $targetAcme,
        tenantKey: $tenantAcme,
        targetKey: 'shared-origin',
        origin: CanonicalOrigin::of('https', 'acme.example.test'),
        destinationPolicyKey: 'default',
        actor: $this->actor(0, 'acme-target'),
    ));

    $headAcme = Uuid::random();
    $policyCommands->update(new UpdatePolicyCommand(
        headId: $headAcme,
        tenant: $tenantAcme,
        scope: PolicyScope::Entity,
        scopeId: 'crawler:shared-42',
        serializedPatch: cacheTestPatchJson([
            'timeout.connect_ms' => ['op' => 'set', 'value' => 1200],
            'routes.denied' => ['op' => 'set', 'value' => ['route-acme-blocked']],
        ]),
        actor: $this->actor(0, 'acme-entity-policy'),
    ));

    // Настраиваем арендатора globex с ТЕМ ЖЕ opaque ID субъекта crawler:shared-42 (connect_ms = 4500)
    $this->useTenant($tenantGlobex);
    $this->commands(true);

    $targetGlobex = Uuid::random();
    $this->commands()->upsertTarget(new UpsertTargetCommand(
        targetId: $targetGlobex,
        tenantKey: $tenantGlobex,
        targetKey: 'shared-origin',
        origin: CanonicalOrigin::of('https', 'globex.example.test'),
        destinationPolicyKey: 'default',
        actor: $this->actor(0, 'globex-target'),
    ));

    $headGlobex = Uuid::random();
    $policyCommands->update(new UpdatePolicyCommand(
        headId: $headGlobex,
        tenant: $tenantGlobex,
        scope: PolicyScope::Entity,
        scopeId: 'crawler:shared-42',
        serializedPatch: cacheTestPatchJson([
            'timeout.connect_ms' => ['op' => 'set', 'value' => 4500],
            'routes.denied' => ['op' => 'set', 'value' => ['route-globex-blocked']],
        ]),
        actor: $this->actor(0, 'globex-entity-policy'),
    ));

    $cache->flush();

    $subjectsAcme = new SubjectChain(new SubjectRef($tenantAcme, 'crawler', 'shared-42'));
    $subjectsGlobex = new SubjectChain(new SubjectRef($tenantGlobex, 'crawler', 'shared-42'));

    // Запрос 1 в воркере: арендатор acme (cache miss -> populate)
    $this->useTenant($tenantAcme);
    $this->commands(true);
    $resolvedAcme1 = $workerResolver->resolveEffective($subjectsAcme, $targetAcme);
    expect($resolvedAcme1->timeouts->connectTimeoutMs)->toBe(1200)
        ->and($resolvedAcme1->deniedRoutes)->toBe(['route-acme-blocked'])
        ->and($cache->misses())->toBe(1)
        ->and($cache->hits())->toBe(0);

    // Запрос 2 в том же воркере: арендатор globex с тем же opaque ID crawler:shared-42 (обязан быть cache miss!)
    $this->useTenant($tenantGlobex);
    $this->commands(true);
    $resolvedGlobex1 = $workerResolver->resolveEffective($subjectsGlobex, $targetGlobex);
    expect($resolvedGlobex1->timeouts->connectTimeoutMs)->toBe(4500)
        ->and($resolvedGlobex1->deniedRoutes)->toBe(['route-globex-blocked'])
        ->and($cache->misses())->toBe(2)
        ->and($cache->hits())->toBe(0);

    // Запрос 3 и 4 в том же воркере: повторные вызовы для acme и globex попадают в свои изолированные записи кэша.
    $this->useTenant($tenantAcme);
    $this->commands(true);
    $resolvedAcme2 = $workerResolver->resolveEffective($subjectsAcme, $targetAcme);
    expect($resolvedAcme2->timeouts->connectTimeoutMs)->toBe(1200)
        ->and($cache->hits())->toBe(1);

    // При смене прав актора в том же воркере на закэшированной записи флаги editable/reason пересчитываются без утечки прав предыдущего запроса.
    $this->permissions(static fn (ActorRef $actor, TenantKey $t, string $ability): bool => $ability === 'policy.resolve');
    $explainDeniedActor = $workerResolver->resolveEffective($subjectsAcme, $targetAcme)->explanation();
    expect($cache->hits())->toBe(2)
        ->and($explainDeniedActor->field('timeout.connect_ms')->editable)->toBeFalse()
        ->and($explainDeniedActor->field('timeout.connect_ms')->reason)->toBe('locked_by_permission');
});

it('инвалидирует кэш только после коммита транзакции, не загрязняет кэш при rollback и изолирует версии политик', function (): void {
    $this->commands(true);
    $policyCommands = $this->app->make(PolicyCommands::class);
    $cache = $this->app->make(PolicyResolutionCache::class);
    $cache->flush();

    /** @var PolicyResolver $resolver */
    $resolver = $this->app->make(PolicyResolverContract::class);

    $targetId = Uuid::random();
    $this->commands()->upsertTarget(new UpsertTargetCommand(
        targetId: $targetId,
        tenantKey: $this->tenant,
        targetKey: 'catalog-api',
        origin: CanonicalOrigin::of('https', 'catalog.example.test'),
        destinationPolicyKey: 'default',
        actor: $this->actor(0, 'target-create'),
    ));

    $headId = Uuid::random();
    $policyCommands->update(new UpdatePolicyCommand(
        headId: $headId,
        tenant: $this->tenant,
        scope: PolicyScope::Tenant,
        scopeId: 'acme',
        serializedPatch: cacheTestPatchJson([
            'timeout.connect_ms' => ['op' => 'set', 'value' => 3000],
        ]),
        actor: $this->actor(0, 'policy-v1'),
    ));

    $cache->flush();
    $subjects = new SubjectChain(new SubjectRef($this->tenant, 'crawler', 'worker-7'));

    // Прогреваем кэш на ревизии 1
    $v1 = $resolver->resolveEffective($subjects, $targetId);
    expect($v1->timeouts->connectTimeoutMs)->toBe(3000)
        ->and($v1->effectiveRevision)->toBe(1)
        ->and($cache->size())->toBe(1)
        ->and($cache->misses())->toBe(1);

    // Повторное чтение берётся из кэша
    $v1Cached = $resolver->resolveEffective($subjects, $targetId);
    expect($v1Cached->timeouts->connectTimeoutMs)->toBe(3000)
        ->and($cache->hits())->toBe(1);

    // Проверяем защиту от загрязнения кэша незакоммиченной транзакцией:
    // очищаем кэш, открываем транзакцию, меняем ревизию напрямую в SQL, вызываем resolveEffective и откатываем транзакцию.
    $cache->flush();
    $this->db->beginTransaction();
    try {
        $uncommittedRevId = Uuid::random();
        $this->table('policy_revisions')->insert([
            'id' => $uncommittedRevId->value,
            'tenant_key' => $this->tenant->value,
            'head_id' => $headId->value,
            'revision' => 2,
            'definition' => cacheTestPatchJson([
                'timeout.connect_ms' => ['op' => 'set', 'value' => 500],
            ]),
        ]);
        $this->table('policy_heads')
            ->where('tenant_key', $this->tenant->value)
            ->where('id', $headId->value)
            ->update([
                'revision' => 2,
                'current_revision_id' => $uncommittedRevId->value,
            ]);

        $insideTx = $resolver->resolveEffective($subjects, $targetId);
        expect($insideTx->timeouts->connectTimeoutMs)->toBe(500)
            ->and($cache->size())->toBe(0); // Внутри открытой транзакции запись в кэш запрещена!
    } finally {
        $this->db->rollBack();
    }

    // После отката транзакции резолвер возвращает закоммиченное значение 3000 мс и кладёт его в кэш.
    $afterRollback = $resolver->resolveEffective($subjects, $targetId);
    expect($afterRollback->timeouts->connectTimeoutMs)->toBe(3000)
        ->and($afterRollback->effectiveRevision)->toBe(1)
        ->and($cache->size())->toBe(1);

    // Теперь коммитим ревизию 2 через UpdatePolicy -> кэш инвалидируется после коммита!
    $invalidationsBefore = $cache->invalidations();
    $policyCommands->update(new UpdatePolicyCommand(
        headId: $headId,
        tenant: $this->tenant,
        scope: PolicyScope::Tenant,
        scopeId: 'acme',
        serializedPatch: cacheTestPatchJson([
            'timeout.connect_ms' => ['op' => 'set', 'value' => 1400],
        ]),
        actor: $this->actor(1, 'policy-v2-commit'),
    ));

    expect($cache->invalidations())->toBe($invalidationsBefore + 1)
        ->and($cache->size())->toBe(0);

    $v2 = $resolver->resolveEffective($subjects, $targetId);
    expect($v2->timeouts->connectTimeoutMs)->toBe(1400)
        ->and($v2->effectiveRevision)->toBe(2);

    // Проверяем, что revalidateLive всегда обходит кэш и читает авторитетный SQL.
    $endpointLive = Uuid::random();
    $routeLive = Uuid::random();
    $profileLive = Uuid::random();
    $this->commands()->upsertEndpoint(new UpsertEndpointCommand(
        endpointId: $endpointLive,
        tenantKey: $this->tenant,
        protocol: ProxyProtocol::Http,
        host: 'live-proxy.example.test',
        port: 8080,
        credential: null,
        actor: $this->actor(0, 'live-endpoint'),
    ));
    $this->commands()->upsertRoute(new UpsertRouteCommand(
        routeId: $routeLive,
        tenantKey: $this->tenant,
        routeKey: 'proxy-live-1',
        kind: RouteKind::Proxy,
        endpointId: $endpointLive,
        enabled: true,
        egress: EgressIdentity::explicit('nat:live-1'),
        independenceGroup: null,
        sharedGrant: false,
        actor: $this->actor(0, 'live-route'),
    ));
    $this->commands()->upsertProfile(new UpsertRouteProfileCommand(
        profileId: $profileLive,
        tenantKey: $this->tenant,
        profileKey: 'default',
        memberRouteIds: [$routeLive],
        strategyKey: 'uniform_random',
        actor: $this->actor(0, 'live-profile'),
    ));

    $pinnedV2 = $resolver->resolveEffective($subjects, $targetId)->pin();

    $policyCommands->update(new UpdatePolicyCommand(
        headId: $headId,
        tenant: $this->tenant,
        scope: PolicyScope::Tenant,
        scopeId: 'acme',
        serializedPatch: cacheTestPatchJson([
            'timeout.connect_ms' => ['op' => 'set', 'value' => 700],
        ]),
        actor: $this->actor(2, 'policy-v3-commit'),
    ));

    // Прогреваем кэш на v3, но проверяем revalidateLive со старым слепком pinnedV2 -> возвращает PolicyChanged без обращения к кэшу.
    $resolver->resolveEffective($subjects, $targetId);
    $hitsBeforeLive = $cache->hits();

    $liveOutcome = $resolver->revalidateLive($pinnedV2, $subjects);
    expect($liveOutcome)->toBeInstanceOf(PolicyChanged::class)
        ->and($cache->hits())->toBe($hitsBeforeLive);
});

it('изолирует ключи кэша по SettingRegistry fingerprint, панели, слоту трафика и упорядоченной цепочке субъектов', function (): void {
    $this->commands(true);
    $cache = $this->app->make(PolicyResolutionCache::class);
    $cache->flush();

    /** @var PolicyResolver $resolver */
    $resolver = $this->app->make(PolicyResolverContract::class);

    $targetId = Uuid::random();
    $this->commands()->upsertTarget(new UpsertTargetCommand(
        targetId: $targetId,
        tenantKey: $this->tenant,
        targetKey: 'multi-dim-origin',
        origin: CanonicalOrigin::of('https', 'dim.example.test'),
        destinationPolicyKey: 'default',
        actor: $this->actor(0, 'dim-target'),
    ));

    $this->reconfigureProxyma([
        'policy' => [
            'panel_defaults' => [
                'admin' => ['timeout.connect_ms' => 1500],
                'partner' => ['timeout.connect_ms' => 3200],
            ],
        ],
    ]);

    $orgA = new SubjectRef($this->tenant, 'organization', 'org-a');
    $orgB = new SubjectRef($this->tenant, 'organization', 'org-b');
    $leaf = new SubjectRef($this->tenant, 'crawler', 'c-10');

    $chainOrgA = new SubjectChain($leaf, [$orgA]);
    $chainOrgB = new SubjectChain($leaf, [$orgB]);

    // 1. Разные панели (admin vs partner) не делят кэш
    $adminPolicy = $resolver->resolveEffective($chainOrgA, $targetId, trafficKey: 'default', panelKey: 'admin');
    $partnerPolicy = $resolver->resolveEffective($chainOrgA, $targetId, trafficKey: 'default', panelKey: 'partner');
    expect($adminPolicy->timeouts->connectTimeoutMs)->toBe(1500)
        ->and($partnerPolicy->timeouts->connectTimeoutMs)->toBe(3200)
        ->and($cache->misses())->toBe(2);

    // 2. Разные слоты трафика (default vs realtime) и разные цепочки предков (org-a vs org-b) дают разные ключи кэша
    $realtimePolicy = $resolver->resolveEffective($chainOrgA, $targetId, trafficKey: 'realtime', panelKey: 'admin');
    $orgBPolicy = $resolver->resolveEffective($chainOrgB, $targetId, trafficKey: 'default', panelKey: 'admin');
    expect($realtimePolicy->trafficKey)->toBe('realtime')
        ->and($orgBPolicy->subjectChain->ancestors()[0]->id)->toBe('org-b')
        ->and($cache->misses())->toBe(4);

    // 3. Смена SettingRegistry (изменение fingerprint) автоматически изолирует кэш
    $customDescriptor = new SettingDescriptor(
        key: 'custom.concurrency_cap',
        type: SettingType::Integer,
        default: 12,
        nullable: false,
        allowedScopes: [PolicyScope::Tenant, PolicyScope::Entity],
        merge: SettingMerge::Minimum,
        minimum: 1,
        maximum: 100,
        permission: 'policy.custom.edit',
    );
    $extendedRegistry = new SettingRegistry([...CoreSettings::descriptors(), $customDescriptor]);
    $this->app->instance(SettingRegistry::class, $extendedRegistry);

    $withExtendedRegistry = $resolver->resolveEffective($chainOrgA, $targetId, trafficKey: 'default', panelKey: 'admin');
    expect($withExtendedRegistry->value('custom.concurrency_cap'))->toBe(12)
        ->and($cache->misses())->toBe(5);
});
