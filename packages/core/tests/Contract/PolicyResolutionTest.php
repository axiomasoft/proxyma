<?php

declare(strict_types=1);

use Proxyma\Application\Policy\CoreSettings;
use Proxyma\Application\Policy\PolicyResolver;
use Proxyma\Application\Policy\SettingRegistry;
use Proxyma\Contracts\PolicyCommands;
use Proxyma\Contracts\PolicyResolver as PolicyResolverContract;
use Proxyma\Contracts\SharedRouteCommands;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\CredentialRef;
use Proxyma\Domain\Catalog\EgressIdentity;
use Proxyma\Domain\Catalog\ProxyProtocol;
use Proxyma\Domain\Catalog\RetireCatalogEntityCommand;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Catalog\SetRouteEnabledCommand;
use Proxyma\Domain\Catalog\ShareRouteCommand;
use Proxyma\Domain\Catalog\UpsertEndpointCommand;
use Proxyma\Domain\Catalog\UpsertRouteCommand;
use Proxyma\Domain\Catalog\UpsertRouteProfileCommand;
use Proxyma\Domain\Catalog\UpsertTargetCommand;
use Proxyma\Domain\Execution\BlockedReason;
use Proxyma\Domain\Execution\ExecutionBlocked;
use Proxyma\Domain\Execution\RequestTimeoutPolicy;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\BudgetConstraint;
use Proxyma\Domain\Policy\InvalidSettingPatch;
use Proxyma\Domain\Policy\LivePolicyAuthority;
use Proxyma\Domain\Policy\NeedsReplan;
use Proxyma\Domain\Policy\PinnedPolicySnapshot;
use Proxyma\Domain\Policy\PolicyChanged;
use Proxyma\Domain\Policy\PolicyLayer;
use Proxyma\Domain\Policy\PolicyLayerKind;
use Proxyma\Domain\Policy\PolicyScope;
use Proxyma\Domain\Policy\SettingChange;
use Proxyma\Domain\Policy\SettingDescriptor;
use Proxyma\Domain\Policy\SettingMerge;
use Proxyma\Domain\Policy\SettingPatch;
use Proxyma\Domain\Policy\SettingType;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Domain\Policy\UpdatePolicyCommand;
use Proxyma\Infrastructure\Cache\PolicyResolutionCache;
use Proxyma\Tests\Support\CatalogTestCase;

require_once __DIR__.'/../Support/CatalogTestCase.php';

uses(CatalogTestCase::class);

/**
 * @param  array<string, SettingChange|mixed>  $entries
 */
function makePatch(array $entries): SettingPatch
{
    $changes = [];

    foreach ($entries as $key => $value) {
        $changes[$key] = $value instanceof SettingChange
            ? $value
            : SettingChange::set($value);
    }

    return new SettingPatch(1, $changes);
}

/**
 * @param  array<string, array<string, mixed>>  $operations
 */
function encodePatchOperations(array $operations, int $schemaVersion = 1): string
{
    return json_encode([
        'schema_version' => $schemaVersion,
        'operations' => (object) $operations,
    ], JSON_THROW_ON_ERROR);
}

it('вычисляет эффективную политику строго в каноническом порядке 10 слоёв независимо от входного порядка', function (): void {
    $tenant = new TenantKey('acme');
    $org = new SubjectRef($tenant, 'organization', 'org-1');
    $dept = new SubjectRef($tenant, 'department', 'dept-1');
    $crawler = new SubjectRef($tenant, 'crawler', 'crawler-1');
    $subjects = new SubjectChain($crawler, [$org, $dept]);
    $targetId = Uuid::random();

    $resolver = new PolicyResolver;

    // Передаём все 10 слоёв (включая два предка в ordered_entity_ancestry) в перемешанном порядке.
    $shuffledLayers = [
        new PolicyLayer(
            kind: PolicyLayerKind::RequestNarrowing,
            scopeKey: 'request:req-99',
            patch: makePatch([
                'timeout.connect_ms' => 450,
                'routes.denied' => ['route-req-deny'],
            ]),
            revision: 10,
        ),
        new PolicyLayer(
            kind: PolicyLayerKind::EntityBinding,
            scopeKey: 'entity:crawler:crawler-1',
            patch: makePatch([
                'timeout.connect_ms' => 600,
                'route.strategy' => 'budget_aware_random',
                'routes.denied' => ['route-entity-deny'],
            ]),
            revision: 8,
            profileKey: 'profile-entity',
            subject: $crawler,
        ),
        new PolicyLayer(
            kind: PolicyLayerKind::PanelDb,
            scopeKey: 'panel:admin',
            patch: makePatch([
                'timeout.connect_ms' => 1200,
                'routes.denied' => ['route-panel-db'],
            ]),
            revision: 6,
        ),
        new PolicyLayer(
            kind: PolicyLayerKind::ProjectDefaults,
            scopeKey: 'project_defaults',
            patch: makePatch([
                'timeout.connect_ms' => 5000,
                'timeout.read_ms' => 8000,
                'timeout.total_ms' => 9000,
                'retry.max_attempts' => 6,
                'routes.denied' => ['route-project'],
            ]),
            revision: 2,
        ),
        new PolicyLayer(
            kind: PolicyLayerKind::OrderedEntityAncestry,
            scopeKey: 'entity:organization:org-1',
            patch: makePatch([
                'timeout.connect_ms' => 900,
                'route.strategy' => 'least_recent_authorized',
                'routes.denied' => ['route-org'],
            ]),
            revision: 7,
            profileKey: 'profile-org',
            subject: $org,
        ),
        new PolicyLayer(
            kind: PolicyLayerKind::OrderedEntityAncestry,
            scopeKey: 'entity:department:dept-1',
            patch: makePatch([
                'timeout.connect_ms' => 750,
                'routes.denied' => ['route-dept'],
            ]),
            revision: 7,
            profileKey: 'profile-dept',
            subject: $dept,
        ),
        new PolicyLayer(
            kind: PolicyLayerKind::GlobalDb,
            scopeKey: 'global:system',
            patch: makePatch([
                'timeout.connect_ms' => 4000,
                'routes.denied' => ['route-global'],
            ]),
            revision: 3,
        ),
        new PolicyLayer(
            kind: PolicyLayerKind::PinnedExecutionSnapshot,
            scopeKey: 'pinned_snapshot',
            patch: makePatch([
                'timeout.connect_ms' => 500,
                'routes.denied' => ['route-pinned'],
            ]),
            revision: 9,
        ),
        new PolicyLayer(
            kind: PolicyLayerKind::TenantDb,
            scopeKey: 'tenant:acme',
            patch: makePatch([
                'timeout.connect_ms' => 2500,
                'routes.denied' => ['route-tenant'],
            ]),
            revision: 4,
        ),
        new PolicyLayer(
            kind: PolicyLayerKind::PanelDefinitionDefaults,
            scopeKey: 'panel_defaults:admin',
            patch: makePatch([
                'timeout.connect_ms' => 1800,
                'routes.denied' => ['route-panel-def'],
            ]),
            revision: 5,
        ),
        new PolicyLayer(
            kind: PolicyLayerKind::PackageDefaults,
            scopeKey: 'package_defaults',
            patch: makePatch([]),
            revision: 1,
        ),
    ];

    $effective = $resolver->resolveFromLayers(
        subjects: $subjects,
        targetId: $targetId,
        layers: $shuffledLayers,
    );

    expect($effective->appliedLayers)->toBe([
        'package_defaults:package_defaults',
        'project_defaults:project_defaults',
        'global_db:global:system',
        'tenant_db:tenant:acme',
        'panel_definition_defaults:panel_defaults:admin',
        'panel_db:panel:admin',
        'ordered_entity_ancestry:entity:organization:org-1',
        'ordered_entity_ancestry:entity:department:dept-1',
        'entity_binding:entity:crawler:crawler-1',
        'pinned_execution_snapshot:pinned_snapshot',
        'request_narrowing:request:req-99',
    ])
        ->and($effective->timeouts->connectTimeoutMs)->toBe(450)
        ->and($effective->routeProfileKey)->toBe('profile-entity')
        ->and($effective->strategyKey)->toBe('budget_aware_random')
        ->and($effective->deniedRoutes)->toBe([
            'route-project',
            'route-global',
            'route-tenant',
            'route-panel-def',
            'route-panel-db',
            'route-org',
            'route-dept',
            'route-entity-deny',
            'route-pinned',
            'route-req-deny',
        ]);

    $connectProvenanceLayers = array_map(
        static fn ($step): PolicyLayerKind => $step->layerKind,
        $effective->explanation()->field('timeout.connect_ms')->provenance,
    );

    expect($connectProvenanceLayers)->toBe([
        PolicyLayerKind::PackageDefaults,
        PolicyLayerKind::ProjectDefaults,
        PolicyLayerKind::GlobalDb,
        PolicyLayerKind::TenantDb,
        PolicyLayerKind::PanelDefinitionDefaults,
        PolicyLayerKind::PanelDb,
        PolicyLayerKind::OrderedEntityAncestry,
        PolicyLayerKind::OrderedEntityAncestry,
        PolicyLayerKind::EntityBinding,
        PolicyLayerKind::PinnedExecutionSnapshot,
        PolicyLayerKind::RequestNarrowing,
    ]);
});

it('соблюдает табличную алгебру слияния полей, кросс-полевые лимиты и состояния missing/null/empty/disable/inherit', function (
    array $layers,
    array $expectedValues,
    array $expectedFieldAssertions,
    array $profileDirectMemberMap = [],
    array $hardBounds = [],
): void {
    $tenant = new TenantKey('acme');
    $subjects = new SubjectChain(new SubjectRef($tenant, 'crawler', 'c-1'));
    $targetId = Uuid::random();

    $resolver = new PolicyResolver(hardBounds: $hardBounds);
    $effective = $resolver->resolveFromLayers(
        subjects: $subjects,
        targetId: $targetId,
        layers: $layers,
        profileDirectMemberMap: $profileDirectMemberMap,
    );

    foreach ($expectedValues as $key => $expectedValue) {
        expect($effective->value($key))->toBe($expectedValue);
    }

    $explanation = $effective->explanation();

    foreach ($expectedFieldAssertions as $fieldKey => $assertions) {
        $fieldExp = $explanation->field($fieldKey);

        if (array_key_exists('sourceScope', $assertions)) {
            expect($fieldExp->sourceScope)->toBe($assertions['sourceScope']);
        }

        if (array_key_exists('sourceLayer', $assertions)) {
            expect($fieldExp->sourceLayer)->toBe($assertions['sourceLayer']);
        }

        if (array_key_exists('sourceRevision', $assertions)) {
            expect($fieldExp->sourceRevision)->toBe($assertions['sourceRevision']);
        }

        if (array_key_exists('clamped', $assertions)) {
            expect($fieldExp->clamped)->toBe($assertions['clamped']);
        }

        if (array_key_exists('clampedByScope', $assertions)) {
            expect($fieldExp->clampedByScope)->toBe($assertions['clampedByScope']);
        }

        if (array_key_exists('clampedByRevision', $assertions)) {
            expect($fieldExp->clampedByRevision)->toBe($assertions['clampedByRevision']);
        }

        if (array_key_exists('disabled', $assertions)) {
            expect($fieldExp->disabled)->toBe($assertions['disabled']);
        }

        if (array_key_exists('inherited', $assertions)) {
            expect($fieldExp->inherited)->toBe($assertions['inherited']);
        }

        if (array_key_exists('reason', $assertions)) {
            expect($fieldExp->reason)->toBe($assertions['reason']);
        }
    }
})->with([
    'minimum зажимает попытку нижнего слоя увеличить таймауты, ретраи и редиректы + зажимает connect/read по total_ms' => function (): array {
        return [
            'layers' => [
                new PolicyLayer(
                    kind: PolicyLayerKind::GlobalDb,
                    scopeKey: 'global:system',
                    patch: makePatch([
                        'timeout.connect_ms' => 3000,
                        'timeout.read_ms' => 6000,
                        'timeout.total_ms' => 8000,
                        'retry.max_attempts' => 4,
                        'http.max_redirects' => 3,
                    ]),
                    revision: 2,
                ),
                new PolicyLayer(
                    kind: PolicyLayerKind::TenantDb,
                    scopeKey: 'tenant:acme',
                    patch: makePatch([
                        'timeout.connect_ms' => 5000, // попытка увеличить > 3000 -> clamped
                        'timeout.total_ms' => 2000,   // сужает total_ms до 2000 -> зажимает connect_ms и read_ms до 2000!
                        'retry.max_attempts' => 2,    // сужает 4 -> 2
                        'http.max_redirects' => 8,    // попытка увеличить > 3 -> clamped
                    ]),
                    revision: 5,
                ),
                new PolicyLayer(
                    kind: PolicyLayerKind::EntityBinding,
                    scopeKey: 'entity:crawler:c-1',
                    patch: makePatch([
                        'retry.max_attempts' => 7, // попытка увеличить > 2 -> clamped_by_upper_bound (tenant:acme@r5)
                    ]),
                    revision: 9,
                ),
            ],
            'expectedValues' => [
                'timeout.total_ms' => 2000,
                'timeout.connect_ms' => 2000,
                'timeout.read_ms' => 2000,
                'retry.max_attempts' => 2,
                'http.max_redirects' => 3,
            ],
            'expectedFieldAssertions' => [
                'timeout.total_ms' => [
                    'sourceScope' => 'tenant:acme',
                    'sourceLayer' => PolicyLayerKind::TenantDb,
                    'sourceRevision' => 5,
                    'clamped' => false,
                    'inherited' => true,
                ],
                'timeout.connect_ms' => [
                    'sourceScope' => 'tenant:acme',
                    'sourceRevision' => 5,
                    'clamped' => true,
                    'clampedByScope' => 'tenant:acme',
                    'clampedByRevision' => 5,
                    'reason' => 'clamped_by_total_timeout',
                ],
                'retry.max_attempts' => [
                    'sourceScope' => 'tenant:acme',
                    'sourceLayer' => PolicyLayerKind::TenantDb,
                    'sourceRevision' => 5,
                    'clamped' => true,
                    'clampedByScope' => 'tenant:acme',
                    'clampedByRevision' => 5,
                    'reason' => 'clamped_by_upper_bound',
                ],
                'http.max_redirects' => [
                    'sourceScope' => 'global:system',
                    'sourceLayer' => PolicyLayerKind::GlobalDb,
                    'sourceRevision' => 2,
                    'clamped' => true,
                    'clampedByScope' => 'global:system',
                    'clampedByRevision' => 2,
                ],
            ],
        ];
    },
    'destinations.allowed различает missing, null и пустой список [] при жёстком пересечении' => function (): array {
        return [
            'layers' => [
                new PolicyLayer(
                    kind: PolicyLayerKind::GlobalDb,
                    scopeKey: 'global:system',
                    patch: makePatch([
                        'destinations.allowed' => null, // явно без ограничений на уровне global
                    ]),
                    revision: 1,
                ),
                new PolicyLayer(
                    kind: PolicyLayerKind::TenantDb,
                    scopeKey: 'tenant:acme',
                    patch: makePatch([
                        'destinations.allowed' => ['dest-a', 'dest-b', 'dest-c'],
                    ]),
                    revision: 3,
                ),
                new PolicyLayer(
                    kind: PolicyLayerKind::PanelDb,
                    scopeKey: 'panel:default',
                    patch: makePatch([
                        'destinations.allowed' => null, // попытка снять ограничение через null -> clamped!
                    ]),
                    revision: 4,
                ),
                new PolicyLayer(
                    kind: PolicyLayerKind::OrderedEntityAncestry,
                    scopeKey: 'entity:organization:org-1',
                    patch: makePatch([
                        'destinations.allowed' => ['dest-b', 'dest-c', 'dest-unallowed'], // пересечение даёт ['dest-b', 'dest-c'] + clamped
                    ]),
                    revision: 6,
                ),
                new PolicyLayer(
                    kind: PolicyLayerKind::EntityBinding,
                    scopeKey: 'entity:crawler:c-1',
                    patch: makePatch([
                        'destinations.allowed' => [], // пустой список [] -> пустое пересечение (блокирует все назначения)
                    ]),
                    revision: 8,
                ),
            ],
            'expectedValues' => [
                'destinations.allowed' => [],
            ],
            'expectedFieldAssertions' => [
                'destinations.allowed' => [
                    'sourceScope' => 'entity:crawler:c-1',
                    'sourceLayer' => PolicyLayerKind::EntityBinding,
                    'sourceRevision' => 8,
                    'clamped' => true,
                    'clampedByScope' => 'tenant:acme',
                    'clampedByRevision' => 3,
                    'inherited' => false,
                ],
            ],
        ];
    },
    'retry.delays_ms поддерживает поэлементный min, disable и блокирует повторное включение на нижнем слое' => function (): array {
        return [
            'layers' => [
                new PolicyLayer(
                    kind: PolicyLayerKind::GlobalDb,
                    scopeKey: 'global:system',
                    patch: makePatch([
                        'retry.delays_ms' => [500, 1000, 2000],
                    ]),
                    revision: 2,
                ),
                new PolicyLayer(
                    kind: PolicyLayerKind::TenantDb,
                    scopeKey: 'tenant:acme',
                    patch: makePatch([
                        'retry.delays_ms' => SettingChange::disable(),
                    ]),
                    revision: 4,
                ),
                new PolicyLayer(
                    kind: PolicyLayerKind::EntityBinding,
                    scopeKey: 'entity:crawler:c-1',
                    patch: makePatch([
                        'retry.delays_ms' => [100, 200],
                    ]),
                    revision: 7,
                ),
            ],
            'expectedValues' => [
                'retry.delays_ms' => [],
            ],
            'expectedFieldAssertions' => [
                'retry.delays_ms' => [
                    'sourceScope' => 'tenant:acme',
                    'sourceLayer' => PolicyLayerKind::TenantDb,
                    'sourceRevision' => 4,
                    'disabled' => true,
                    'clamped' => true,
                    'clampedByScope' => 'tenant:acme',
                    'clampedByRevision' => 4,
                    'reason' => 'clamped_by_disabled_upper_scope',
                ],
            ],
        ];
    },
    'inherit удаляет локальный override на том же уровне и возвращает значение и ревизию верхнего слоя' => function (): array {
        return [
            'layers' => [
                new PolicyLayer(
                    kind: PolicyLayerKind::TenantDb,
                    scopeKey: 'tenant:acme',
                    patch: makePatch([
                        'timeout.connect_ms' => 2200,
                    ]),
                    revision: 3,
                ),
                new PolicyLayer(
                    kind: PolicyLayerKind::EntityBinding,
                    scopeKey: 'entity:crawler:c-1',
                    patch: makePatch([
                        'timeout.connect_ms' => 900,
                    ]),
                    revision: 5,
                ),
                new PolicyLayer(
                    kind: PolicyLayerKind::EntityBinding,
                    scopeKey: 'entity:crawler:c-1',
                    patch: makePatch([
                        'timeout.connect_ms' => SettingChange::inherit(),
                    ]),
                    revision: 6,
                ),
            ],
            'expectedValues' => [
                'timeout.connect_ms' => 2200,
            ],
            'expectedFieldAssertions' => [
                'timeout.connect_ms' => [
                    'sourceScope' => 'tenant:acme',
                    'sourceLayer' => PolicyLayerKind::TenantDb,
                    'sourceRevision' => 3,
                    'inherited' => true,
                    'reason' => 'inherited',
                ],
            ],
        ];
    },
    'route.allow_direct требует и разрешения политики, и наличия direct-маршрута в выбранном профиле' => function (): array {
        return [
            'layers' => [
                new PolicyLayer(
                    kind: PolicyLayerKind::TenantDb,
                    scopeKey: 'tenant:acme',
                    patch: makePatch([
                        'route.allow_direct' => true,
                    ]),
                    revision: 2,
                    profileKey: 'proxy-only-profile',
                ),
            ],
            'expectedValues' => [
                'route.allow_direct' => false,
            ],
            'expectedFieldAssertions' => [
                'route.allow_direct' => [
                    'clamped' => true,
                    'reason' => 'requires_explicit_direct_profile_member',
                ],
            ],
            'profileDirectMemberMap' => [
                'proxy-only-profile' => false,
            ],
        ];
    },
]);

it('сохраняет все активные бюджеты разных уровней одновременно и пересекает одноимённые бюджеты без расширения в устаревшем слепке', function (): void {
    $tenant = new TenantKey('acme');
    $org = new SubjectRef($tenant, 'organization', 'org-1');
    $crawler = new SubjectRef($tenant, 'crawler', 'c-1');
    $subjects = new SubjectChain($crawler, [$org]);
    $targetId = Uuid::random();

    $resolver = new PolicyResolver;

    $liveLayers = [
        new PolicyLayer(
            kind: PolicyLayerKind::GlobalDb,
            scopeKey: 'global:system',
            patch: makePatch([
                'timeout.connect_ms' => 1500,
                'routes.denied' => ['route-revoked-live'],
                'destinations.allowed' => ['dest-1', 'dest-2'],
                'route.allow_direct' => false,
            ]),
            revision: 3,
            allowedProfileKeys: ['profile-main', 'profile-backup'],
            budgets: [
                new BudgetConstraint('global:system', emissionIntervalUs: 1_000, burst: 100, maxInflight: 50, mandatory: true),
            ],
        ),
        new PolicyLayer(
            kind: PolicyLayerKind::TenantDb,
            scopeKey: 'tenant:acme',
            patch: makePatch([
                'timeout.connect_ms' => 800,
            ]),
            revision: 5,
            profileKey: 'profile-main',
            budgets: [
                new BudgetConstraint('tenant:acme', emissionIntervalUs: 5_000, burst: 20, maxInflight: 10, mandatory: true),
            ],
        ),
        new PolicyLayer(
            kind: PolicyLayerKind::OrderedEntityAncestry,
            scopeKey: 'entity:organization:org-1',
            patch: makePatch([]),
            revision: 2,
            profileKey: 'profile-forbidden-by-global', // вне allowedProfileKeys -> игнорируется, пулы не объединяются
            budgets: [
                new BudgetConstraint('entity:organization:org-1', emissionIntervalUs: 10_000, burst: 10, maxInflight: 5),
            ],
        ),
        new PolicyLayer(
            kind: PolicyLayerKind::EntityBinding,
            scopeKey: 'entity:crawler:c-1',
            patch: makePatch([]),
            revision: 4,
            budgets: [
                new BudgetConstraint('entity:crawler:c-1', emissionIntervalUs: 20_000, burst: 5, maxInflight: 2),
            ],
        ),
    ];

    $liveEffective = $resolver->resolveFromLayers($subjects, $targetId, $liveLayers);

    // Все 4 бюджета разных уровней активны одновременно, профиль остался profile-main.
    expect($liveEffective->routeProfileKey)->toBe('profile-main')
        ->and(array_keys($liveEffective->budgets))->toBe([
            'entity:crawler:c-1',
            'entity:organization:org-1',
            'global:system',
            'tenant:acme',
        ]);

    // Создаём устаревший слепок, в котором:
    // - запрещённый в live маршрут 'route-revoked-live' ещё НЕ был запрещён,
    // - таймаут был выше (3000 мс вместо 800 мс),
    // - allowDirectRoute был true (тогда как в live он false),
    // - destinations.allowed включал 'dest-3' (отозванный в live),
    // - бюджет tenant:acme имел более мягкие лимиты (interval 1000 < 5000, burst 100 > 20, maxInflight 40 > 10).
    $staleSnapshot = new PinnedPolicySnapshot(
        tenantKey: $tenant,
        targetId: $targetId,
        destinationPolicyKey: 'default',
        routeProfileId: Uuid::random(),
        routeProfileKey: 'profile-backup',
        routeProfileRevision: 1,
        effectiveRevision: 2,
        policyHash: $liveEffective->policyHash,
        layerRevisions: ['tenant_db:tenant:acme' => 1],
        strategyKey: 'least_recent_authorized',
        outcomePolicyKey: 'custom-outcome',
        timeouts: new RequestTimeoutPolicy(
            connectTimeoutMs: 3000,
            readTimeoutMs: 10000,
            totalTimeoutMs: 10000,
            maxAttempts: 5,
            maxDistinctRoutes: 5,
            maxRedirects: 4,
            retryDelaysMs: [100, 200],
        ),
        retryDelaysDisabled: false,
        allowDirectRoute: true,
        deniedRoutes: ['route-old-deny'],
        allowedDestinations: ['dest-2', 'dest-3'],
        budgets: [
            new BudgetConstraint('tenant:acme', emissionIntervalUs: 1_000, burst: 100, maxInflight: 40, mandatory: false),
        ],
    );

    // Проверяем сериализацию/десериализацию PinnedPolicySnapshot и защиту от повреждённого JSON.
    $encodedSnapshot = $staleSnapshot->encode();
    $decodedSnapshot = PinnedPolicySnapshot::decode($encodedSnapshot);
    expect($decodedSnapshot->encode())->toBe($encodedSnapshot);
    expect(static fn (): PinnedPolicySnapshot => PinnedPolicySnapshot::decode('{"schema_version":2}'))
        ->toThrow(InvalidSettingPatch::class);

    $withStaleSnapshot = $resolver->resolveFromLayers(
        subjects: $subjects,
        targetId: $targetId,
        layers: [...$liveLayers, $decodedSnapshot->toPolicyLayer()],
    );

    // Устаревший слепок:
    // 1) НЕ восстанавливает запрещённый в live маршрут 'route-revoked-live' (Union сохраняет оба запрета)
    // 2) НЕ увеличивает connectTimeoutMs (остаётся 800, а не 3000)
    // 3) НЕ включает allowDirectRoute (остаётся false)
    // 4) НЕ возвращает 'dest-3' (Intersection сужает до ['dest-2'])
    // 5) НЕ увеличивает бюджет tenant:acme (остаётся interval 5000, burst 20, maxInflight 10, mandatory true)
    expect($withStaleSnapshot->isRouteDenied('route-revoked-live'))->toBeTrue()
        ->and($withStaleSnapshot->isRouteDenied('route-old-deny'))->toBeTrue()
        ->and($withStaleSnapshot->timeouts->connectTimeoutMs)->toBe(800)
        ->and($withStaleSnapshot->allowDirectRoute)->toBeFalse()
        ->and($withStaleSnapshot->allowedDestinations)->toBe(['dest-2'])
        ->and($withStaleSnapshot->budgets['tenant:acme']->emissionIntervalUs)->toBe(5_000)
        ->and($withStaleSnapshot->budgets['tenant:acme']->burst)->toBe(20)
        ->and($withStaleSnapshot->budgets['tenant:acme']->maxInflight)->toBe(10)
        ->and($withStaleSnapshot->budgets['tenant:acme']->mandatory)->toBeTrue()
        ->and($withStaleSnapshot->explanation()->field('routes.denied')->clamped)->toBeTrue()
        ->and($withStaleSnapshot->explanation()->field('routes.denied')->reason)->toBe('stale_snapshot_cannot_restore_denied_route')
        ->and($withStaleSnapshot->explanation()->field('timeout.connect_ms')->clamped)->toBeTrue()
        ->and($withStaleSnapshot->explanation()->field('timeout.connect_ms')->reason)->toBe('clamped_stale_snapshot_limit');
});

it('формирует explain с проверкой прав актора, блокировкой верхними границами и редактированием sensitive-полей, а также чистый preview без записи в БД и резервирования квот', function (): void {
    $this->commands();
    $policyCommands = $this->app->make(PolicyCommands::class);

    $sensitiveDescriptor = new SettingDescriptor(
        key: 'internal.masked_marker',
        type: SettingType::Identifier,
        default: null,
        nullable: true,
        allowedScopes: [PolicyScope::Tenant, PolicyScope::Entity],
        merge: SettingMerge::Replace,
        minimum: null,
        maximum: null,
        permission: 'policy.internal.edit',
        sensitive: true,
        allowedValues: ['alpha', 'beta'],
    );

    $registry = new SettingRegistry([...CoreSettings::descriptors(), $sensitiveDescriptor]);
    $this->app->instance(SettingRegistry::class, $registry);

    $tenantHeadId = Uuid::random();
    $policyCommands->update(new UpdatePolicyCommand(
        headId: $tenantHeadId,
        tenant: $this->tenant,
        scope: PolicyScope::Tenant,
        scopeId: 'acme',
        serializedPatch: encodePatchOperations([
            'timeout.connect_ms' => ['op' => 'set', 'value' => 1500],
            'route.allow_direct' => ['op' => 'set', 'value' => false],
            'destinations.allowed' => ['op' => 'set', 'value' => ['origin-a', 'origin-b']],
        ]),
        actor: $this->actor(0, 'seed-tenant-policy'),
    ));

    $budgetId = Uuid::random();
    $this->table('budgets')->insert([
        'id' => $budgetId->value,
        'tenant_key' => $this->tenant->value,
        'scope_key' => 'tenant:acme',
        'emission_interval_us' => 10_000,
        'burst' => 25,
        'max_inflight' => 5,
        'mandatory' => true,
        'revision' => 1,
    ]);

    $subjects = new SubjectChain(new SubjectRef($this->tenant, 'crawler', '42'));
    $targetId = Uuid::random();
    $this->commands()->upsertTarget(new UpsertTargetCommand(
        targetId: $targetId,
        tenantKey: $this->tenant,
        targetKey: 'origin-main',
        origin: CanonicalOrigin::of('https', 'api.example.test'),
        destinationPolicyKey: 'origin-a',
        actor: $this->actor(0, 'seed-target'),
    ));

    /** @var PolicyResolver $resolver */
    $resolver = $this->app->make(PolicyResolverContract::class);

    // 1. Актор с полными правами: timeout.connect_ms редактируемо (в пределах 1..1500),
    // но route.allow_direct заблокировано верхним слоем (false на уровне tenant),
    // а sensitive-поле скрыто из explain()->fields и редактировано в null.
    $explanation = $resolver->explain($subjects, $targetId);
    expect(isset($explanation->fields['internal.masked_marker']))->toBeFalse()
        ->and($explanation->effectivePolicy?->explanations['internal.masked_marker']->effectiveValue)->toBeNull()
        ->and($explanation->effectivePolicy?->explanations['internal.masked_marker']->reason)->toBe('redacted_sensitive_field')
        ->and($explanation->field('timeout.connect_ms')->effectiveValue)->toBe(1500)
        ->and($explanation->field('timeout.connect_ms')->sourceScope)->toBe('tenant:acme')
        ->and($explanation->field('timeout.connect_ms')->sourceRevision)->toBe(1)
        ->and($explanation->field('timeout.connect_ms')->maximumBound)->toBe(1500)
        ->and($explanation->field('timeout.connect_ms')->editable)->toBeTrue()
        ->and($explanation->field('route.allow_direct')->editable)->toBeFalse()
        ->and($explanation->field('route.allow_direct')->reason)->toBe('locked_by_upper_bound');

    // 2. Ограничиваем права актора (запрещаем policy.timeout.edit) -> поле помечается editable=false, reason=locked_by_permission.
    $this->permissions(static fn (ActorRef $actor, TenantKey $t, string $ability): bool => $ability !== 'policy.timeout.edit');

    $restrictedExplain = $resolver->explain($subjects, $targetId);
    expect($restrictedExplain->field('timeout.connect_ms')->editable)->toBeFalse()
        ->and($restrictedExplain->field('timeout.connect_ms')->reason)->toBe('locked_by_permission')
        ->and($restrictedExplain->field('routes.denied')->editable)->toBeTrue();

    // Возвращаем полные права для preview.
    $this->commands(true);

    $headsCountBefore = $this->table('policy_heads')->count();
    $revisionsCountBefore = $this->table('policy_revisions')->count();
    $auditCountBefore = $this->table('audit_events')->count();
    $reservationsCountBefore = $this->table('reservations')->count();
    $cache = $this->app->make(PolicyResolutionCache::class);
    $cache->flush();

    // 3. Предпросмотр (preview) несохранённого патча на уровне entity:
    // - timeout.connect_ms: пытаемся поставить 4000 (будет зажато верхней границей 1500)
    // - timeout.read_ms: сужаем 10000 -> 3500
    // - destinations.allowed: пересекаем ['origin-b', 'origin-c'] -> остаётся ['origin-b'] (с флагом clamped)
    $unsavedPatchJson = encodePatchOperations([
        'timeout.connect_ms' => ['op' => 'set', 'value' => 4000],
        'timeout.read_ms' => ['op' => 'set', 'value' => 3500],
        'destinations.allowed' => ['op' => 'set', 'value' => ['origin-b', 'origin-c']],
    ]);

    $preview = $resolver->preview(
        subjects: $subjects,
        targetId: $targetId,
        scope: PolicyScope::Entity,
        scopeId: 'crawler:42',
        unsavedPatch: $unsavedPatchJson,
    );

    expect($preview->diff)->toHaveKeys(['destinations.allowed', 'timeout.connect_ms', 'timeout.read_ms'])
        ->and($preview->diff['timeout.connect_ms']->beforeValue)->toBe(1500)
        ->and($preview->diff['timeout.connect_ms']->afterValue)->toBe(1500)
        ->and($preview->diff['timeout.connect_ms']->clamped)->toBeTrue()
        ->and($preview->diff['timeout.connect_ms']->reason)->toBe('clamped_by_upper_bound')
        ->and($preview->diff['timeout.read_ms']->beforeValue)->toBe(10000)
        ->and($preview->diff['timeout.read_ms']->afterValue)->toBe(3500)
        ->and($preview->diff['timeout.read_ms']->clamped)->toBeFalse()
        ->and($preview->diff['destinations.allowed']->beforeValue)->toBe(['origin-a', 'origin-b'])
        ->and($preview->diff['destinations.allowed']->afterValue)->toBe(['origin-b'])
        ->and($preview->diff['destinations.allowed']->clamped)->toBeTrue()
        ->and($preview->budgets)->toHaveKey('tenant:acme')
        ->and($this->table('policy_heads')->count())->toBe($headsCountBefore)
        ->and($this->table('policy_revisions')->count())->toBe($revisionsCountBefore)
        ->and($this->table('audit_events')->count())->toBe($auditCountBefore)
        ->and($this->table('reservations')->count())->toBe($reservationsCountBefore)
        ->and($cache->size())->toBe(0);
});

it('выполняет авторитетную SQL-проверку revalidateLive: возвращает PolicyChanged, NeedsReplan и ExecutionBlocked при отзыве маршрута, ротации кредов, отзыве гранта и не откатывается на direct', function (): void {
    $catalog = $this->commands();
    $policyCommands = $this->app->make(PolicyCommands::class);
    $sharedCommands = $this->app->make(SharedRouteCommands::class);

    $endpointA = Uuid::random();
    $endpointB = Uuid::random();
    $routeProxyA = Uuid::random();
    $routeProxyB = Uuid::random();
    $routeDirect = Uuid::random();
    $profileId = Uuid::random();
    $targetId = Uuid::random();

    $catalog->upsertEndpoint(new UpsertEndpointCommand(
        endpointId: $endpointA,
        tenantKey: $this->tenant,
        protocol: ProxyProtocol::Http,
        host: 'proxy-a.example.test',
        port: 8080,
        credential: new CredentialRef('vault:proxy-a', 1),
        actor: $this->actor(0, 'ep-a'),
    ));
    $catalog->upsertEndpoint(new UpsertEndpointCommand(
        endpointId: $endpointB,
        tenantKey: $this->tenant,
        protocol: ProxyProtocol::Http,
        host: 'proxy-b.example.test',
        port: 8080,
        credential: new CredentialRef('vault:proxy-b', 1),
        actor: $this->actor(0, 'ep-b'),
    ));

    $catalog->upsertRoute(new UpsertRouteCommand(
        routeId: $routeProxyA,
        tenantKey: $this->tenant,
        routeKey: 'proxy-route-a',
        kind: RouteKind::Proxy,
        endpointId: $endpointA,
        enabled: true,
        egress: EgressIdentity::explicit('egress:proxy-a'),
        independenceGroup: 'isp-a',
        sharedGrant: false,
        actor: $this->actor(0, 'route-a'),
    ));
    $catalog->upsertRoute(new UpsertRouteCommand(
        routeId: $routeProxyB,
        tenantKey: $this->tenant,
        routeKey: 'proxy-route-b',
        kind: RouteKind::Proxy,
        endpointId: $endpointB,
        enabled: true,
        egress: EgressIdentity::explicit('egress:proxy-b'),
        independenceGroup: 'isp-b',
        sharedGrant: false,
        actor: $this->actor(0, 'route-b'),
    ));
    $catalog->upsertRoute(new UpsertRouteCommand(
        routeId: $routeDirect,
        tenantKey: $this->tenant,
        routeKey: 'direct-fallback-route',
        kind: RouteKind::Direct,
        endpointId: null,
        enabled: true,
        egress: EgressIdentity::explicit('egress:direct'),
        independenceGroup: null,
        sharedGrant: false,
        actor: $this->actor(0, 'route-direct'),
    ));

    $catalog->upsertProfile(new UpsertRouteProfileCommand(
        profileId: $profileId,
        tenantKey: $this->tenant,
        profileKey: 'resilient-pool',
        memberRouteIds: [$routeProxyA, $routeProxyB, $routeDirect],
        strategyKey: 'uniform_random',
        actor: $this->actor(0, 'profile-create'),
    ));

    $catalog->upsertTarget(new UpsertTargetCommand(
        targetId: $targetId,
        tenantKey: $this->tenant,
        targetKey: 'supplier-portal',
        origin: CanonicalOrigin::of('https', 'portal.example.test'),
        destinationPolicyKey: 'supplier-api',
        actor: $this->actor(0, 'target-create'),
    ));

    $tenantHeadId = Uuid::random();
    $policyCommands->update(new UpdatePolicyCommand(
        headId: $tenantHeadId,
        tenant: $this->tenant,
        scope: PolicyScope::Tenant,
        scopeId: 'acme',
        serializedPatch: encodePatchOperations([
            'timeout.connect_ms' => ['op' => 'set', 'value' => 2000],
            'route.allow_direct' => ['op' => 'set', 'value' => false],
            'destinations.allowed' => ['op' => 'set', 'value' => ['supplier-api']],
        ]),
        actor: $this->actor(0, 'tenant-policy-v1'),
    ));

    $subject = new SubjectRef($this->tenant, 'crawler', 'worker-1');
    $subjects = new SubjectChain($subject);

    $this->table('bindings')->insert([
        'id' => Uuid::random()->value,
        'tenant_key' => $this->tenant->value,
        'subject_type' => $subject->type,
        'subject_id' => $subject->id,
        'head_id' => $tenantHeadId->value,
        'profile_id' => $profileId->value,
        'revision' => 1,
    ]);

    /** @var PolicyResolver $resolver */
    $resolver = $this->app->make(PolicyResolverContract::class);
    $effective = $resolver->resolveEffective($subjects, $targetId);
    $pinned = $effective->pin();

    // 1. Валидное live-состояние -> LivePolicyAuthority (direct-маршрут отфильтрован, т.к. allowDirectRoute = false).
    $liveAuth = $resolver->revalidateLive($pinned, $subjects);
    expect($liveAuth)->toBeInstanceOf(LivePolicyAuthority::class);
    /** @var LivePolicyAuthority $liveAuth */
    expect(array_map(static fn ($r): string => $r->routeKey, $liveAuth->eligibleRoutes))
        ->toBe(['proxy-route-a', 'proxy-route-b']);

    $selectedRouteA = $liveAuth->eligibleRoutes[0];
    $initialHealthEpochs = $liveAuth->healthEpochs;

    // 2. Обновляем политику в БД (сужаем connect_ms до 900 и запрещаем proxy-route-a) ->
    //    при проверке без selectedRoute возвращается PolicyChanged с жёстким пересечением;
    //    при проверке с selectedRouteA возвращается NeedsReplan(selected_route_denied).
    $policyCommands->update(new UpdatePolicyCommand(
        headId: $tenantHeadId,
        tenant: $this->tenant,
        scope: PolicyScope::Tenant,
        scopeId: 'acme',
        serializedPatch: encodePatchOperations([
            'timeout.connect_ms' => ['op' => 'set', 'value' => 900],
            'routes.denied' => ['op' => 'set', 'value' => ['proxy-route-a']],
        ]),
        actor: $this->actor(1, 'tenant-policy-v2'),
    ));

    $policyChanged = $resolver->revalidateLive($pinned, $subjects);
    expect($policyChanged)->toBeInstanceOf(PolicyChanged::class);
    /** @var PolicyChanged $policyChanged */
    expect($policyChanged->changedFields)->toContain('timeout.connect_ms', 'routes.denied')
        ->and($policyChanged->livePolicy->timeouts->connectTimeoutMs)->toBe(900)
        ->and($policyChanged->livePolicy->isRouteDenied('proxy-route-a'))->toBeTrue();

    $replanOnDenied = $resolver->revalidateLive($pinned, $subjects, selectedRoute: $selectedRouteA);
    expect($replanOnDenied)->toBeInstanceOf(NeedsReplan::class);
    /** @var NeedsReplan $replanOnDenied */
    expect($replanOnDenied->reason)->toBe('selected_route_denied')
        ->and($replanOnDenied->affectedRoutes)->toBe(['proxy-route-a']);

    // 3. Ротация версии кредов на endpointB для выбранного маршрута B -> NeedsReplan(credential_rotated).
    $selectedRouteB = $liveAuth->eligibleRoutes[1];
    $catalog->upsertEndpoint(new UpsertEndpointCommand(
        endpointId: $endpointB,
        tenantKey: $this->tenant,
        protocol: ProxyProtocol::Http,
        host: 'proxy-b.example.test',
        port: 8080,
        credential: new CredentialRef('vault:proxy-b', 2),
        actor: $this->actor(1, 'ep-b-rotate-cred'),
    ));

    $replanOnCredRotation = $resolver->revalidateLive($pinned, $subjects, selectedRoute: $selectedRouteB);
    expect($replanOnCredRotation)->toBeInstanceOf(NeedsReplan::class);
    /** @var NeedsReplan $replanOnCredRotation */
    expect($replanOnCredRotation->reason)->toBe('credential_rotated');

    // 4. Изменение health_epoch в таблице pxm_route_health -> NeedsReplan(health_epoch_changed).
    $this->table('route_health')
        ->where('tenant_key', $this->tenant->value)
        ->where('route_id', $routeProxyB->value)
        ->update([
            'state' => 'closed',
            'health_epoch' => 3,
            'state_version' => 2,
        ]);

    $replanOnHealthEpoch = $resolver->revalidateLive(
        pinned: $resolver->resolveEffective($subjects, $targetId)->pin(),
        subjects: $subjects,
        pinnedHealthEpochs: $initialHealthEpochs,
    );
    expect($replanOnHealthEpoch)->toBeInstanceOf(NeedsReplan::class);
    /** @var NeedsReplan $replanOnHealthEpoch */
    expect($replanOnHealthEpoch->reason)->toBe('health_epoch_changed');

    // 5. Отключаем последний живой прокси-маршрут proxy-route-b:
    //    в профиле остался только direct-fallback-route, но allowDirectRoute = false!
    //    Рантайм ОБЯЗАН вернуть ExecutionBlocked(DirectRouteNotAllowed), никогда не откатываясь на direct!
    $catalog->setRouteEnabled(new SetRouteEnabledCommand(
        routeId: $routeProxyB,
        tenantKey: $this->tenant,
        enabled: false,
        actor: $this->actor(1, 'disable-route-b'),
    ));

    $blockedNoProxy = $resolver->revalidateLive($pinned, $subjects);
    expect($blockedNoProxy)->toBeInstanceOf(ExecutionBlocked::class);
    /** @var ExecutionBlocked $blockedNoProxy */
    expect($blockedNoProxy->reason)->toBe(BlockedReason::DirectRouteNotAllowed);

    // 6. Проверяем отзыв кросс-тенантного гранта (shared_route_grants) и вывод target из эксплуатации.
    $ownerTenant = new TenantKey('provider');
    $sharedEndpoint = Uuid::random();
    $sharedRouteId = Uuid::random();
    $grantId = Uuid::random();

    $this->useTenant($ownerTenant);
    $this->commands(true);
    $catalog->upsertEndpoint(new UpsertEndpointCommand(
        endpointId: $sharedEndpoint,
        tenantKey: $ownerTenant,
        protocol: ProxyProtocol::Http,
        host: 'shared.example.test',
        port: 8080,
        credential: new CredentialRef('vault:shared', 1),
        actor: $this->actor(0, 'shared-ep'),
    ));
    $catalog->upsertRoute(new UpsertRouteCommand(
        routeId: $sharedRouteId,
        tenantKey: $ownerTenant,
        routeKey: 'shared-route-1',
        kind: RouteKind::Proxy,
        endpointId: $sharedEndpoint,
        enabled: true,
        egress: EgressIdentity::explicit('egress:shared-1'),
        independenceGroup: null,
        sharedGrant: true,
        actor: $this->actor(0, 'shared-route'),
    ));
    $sharedCommands->shareRoute(new ShareRouteCommand(
        grantId: $grantId,
        owner: $ownerTenant,
        consumer: $this->tenant,
        routeId: $sharedRouteId,
        actor: $this->actor(0, 'grant-create'),
    ));

    $this->useTenant($this->tenant);
    $this->commands(true);
    $catalog->upsertProfile(new UpsertRouteProfileCommand(
        profileId: $profileId,
        tenantKey: $this->tenant,
        profileKey: 'resilient-pool',
        memberRouteIds: [$sharedRouteId],
        strategyKey: 'uniform_random',
        actor: $this->actor(1, 'profile-use-shared'),
    ));

    $sharedPinned = $resolver->resolveEffective($subjects, $targetId)->pin();
    $sharedLiveAuth = $resolver->revalidateLive($sharedPinned, $subjects);
    expect($sharedLiveAuth)->toBeInstanceOf(LivePolicyAuthority::class);
    /** @var LivePolicyAuthority $sharedLiveAuth */
    $selectedSharedRoute = $sharedLiveAuth->eligibleRoutes[0];

    // Отзываем грант у владельца маршрута -> при перепроверке у потребителя возвращается ExecutionBlocked(EmptyProfile).
    $this->useTenant($ownerTenant);
    $this->commands(true);
    $sharedCommands->revokeRouteGrant(new RetireCatalogEntityCommand(
        entityId: $grantId,
        tenantKey: $ownerTenant,
        actor: $this->actor(1, 'grant-revoke'),
    ));

    $this->useTenant($this->tenant);
    $this->commands(true);
    $blockedAfterGrantRevoke = $resolver->revalidateLive($sharedPinned, $subjects, selectedRoute: $selectedSharedRoute);
    expect($blockedAfterGrantRevoke)->toBeInstanceOf(ExecutionBlocked::class);
    /** @var ExecutionBlocked $blockedAfterGrantRevoke */
    expect($blockedAfterGrantRevoke->reason)->toBe(BlockedReason::EmptyProfile);

    // Выводим target из эксплуатации -> ExecutionBlocked(TargetRetired).
    $catalog->retireTarget(new RetireCatalogEntityCommand(
        entityId: $targetId,
        tenantKey: $this->tenant,
        actor: $this->actor(1, 'retire-target'),
    ));

    $blockedRetiredTarget = $resolver->revalidateLive($sharedPinned, $subjects);
    expect($blockedRetiredTarget)->toBeInstanceOf(ExecutionBlocked::class);
    /** @var ExecutionBlocked $blockedRetiredTarget */
    expect($blockedRetiredTarget->reason)->toBe(BlockedReason::TargetRetired);
});
