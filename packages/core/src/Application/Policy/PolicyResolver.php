<?php

declare(strict_types=1);

namespace Proxyma\Application\Policy;

use Closure;
use Illuminate\Database\Connection;
use Packman\Attributes\Api;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Contracts\Policy\PolicyResolver as PolicyResolverContract;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Execution\BlockedReason;
use Proxyma\Domain\Execution\ExecutionBlocked;
use Proxyma\Domain\Execution\RequestTimeoutPolicy;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\HealthEpochMap;
use Proxyma\Domain\Panels\SubjectResolutionFailed;
use Proxyma\Domain\Policy\EffectivePolicy;
use Proxyma\Domain\Policy\EffectivePolicySnapshot;
use Proxyma\Domain\Policy\FieldExplanation;
use Proxyma\Domain\Policy\InvalidSettingPatch;
use Proxyma\Domain\Policy\LivePolicyAuthority;
use Proxyma\Domain\Policy\NeedsReplan;
use Proxyma\Domain\Policy\PinnedPolicySnapshot;
use Proxyma\Domain\Policy\PolicyChanged;
use Proxyma\Domain\Policy\PolicyExplanation;
use Proxyma\Domain\Policy\PolicyFieldDiff;
use Proxyma\Domain\Policy\PolicyLayer;
use Proxyma\Domain\Policy\PolicyLayerKind;
use Proxyma\Domain\Policy\PolicyPreview;
use Proxyma\Domain\Policy\PolicyScope;
use Proxyma\Domain\Policy\SettingDescriptor;
use Proxyma\Domain\Policy\SettingPatch;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Cache\PolicyResolutionCache;
use Proxyma\Infrastructure\Database\CatalogRowMapper;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Internal\Database\RowValues;
use Proxyma\Internal\Policy\PolicyDatabaseInspector;
use Proxyma\Internal\Policy\PolicySettingMerger;
use RuntimeException;

/**
 * Детерминированный 10-слойный резолвер эффективной политики (EffectivePolicy),
 * построитель объяснений (explain), предпросмотра (preview)
 * и авторитетной live-проверки безопасности (revalidateLive).
 */
#[Api(since: '1.0')]
final class PolicyResolver implements PolicyResolverContract
{
    private readonly SettingRegistry $fallbackRegistry;

    private readonly PolicySettingMerger $merger;

    private readonly PolicyDatabaseInspector $inspector;

    /**
     * @param  (Closure(): SettingRegistry)|null  $registryResolver
     * @param  (Closure(): array<string, mixed>)|null  $policyConfigResolver
     * @param  array<string, mixed>  $projectDefaults
     * @param  array<string, array<string, mixed>>  $panelDefaults
     * @param  array<string, mixed>  $hardBounds
     */
    public function __construct(
        private readonly ?Connection $db = null,
        private readonly ?CatalogAccess $access = null,
        private readonly ?SettingRegistry $registry = null,
        private readonly ?PolicyResolutionCache $cache = null,
        private readonly ?Closure $registryResolver = null,
        private readonly ?Closure $policyConfigResolver = null,
        private readonly array $projectDefaults = [],
        private readonly array $panelDefaults = [],
        private readonly array $hardBounds = [],
        private readonly string $defaultPanelKey = 'default',
        private readonly ?DefinitionRegistry $definitions = null,
    ) {
        $this->fallbackRegistry = $registry ?? new SettingRegistry(CoreSettings::descriptors());
        $this->merger = new PolicySettingMerger;
        $this->inspector = new PolicyDatabaseInspector(
            db: $this->db,
            access: $this->access,
            definitions: $this->definitions,
            requireConnection: $this->requireConnection(...),
            currentRegistry: $this->currentRegistry(...),
            resolvedProjectDefaults: $this->resolvedProjectDefaults(...),
            resolvedPanelDefaults: $this->resolvedPanelDefaults(...),
        );
    }

    public function resolve(
        SubjectChain $subjects,
        Uuid $targetId,
        string $trafficKey = 'default',
    ): EffectivePolicySnapshot {
        return $this->resolveEffective(
            subjects: $subjects,
            targetId: $targetId,
            trafficKey: $trafficKey,
        )->snapshot();
    }

    public function explain(
        SubjectChain $subjects,
        Uuid $targetId,
        string $trafficKey = 'default',
        ?PolicyScope $editableScope = null,
        ?string $editableScopeId = null,
        ?string $panelKey = null,
    ): PolicyExplanation {
        return $this->resolveEffective(
            subjects: $subjects,
            targetId: $targetId,
            trafficKey: $trafficKey,
            panelKey: $panelKey,
            editableScope: $editableScope,
            editableScopeId: $editableScopeId,
            bypassCache: $editableScope !== null || $editableScopeId !== null,
        )->explanation();
    }

    public function resolveEffective(
        SubjectChain $subjects,
        Uuid $targetId,
        string $trafficKey = 'default',
        ?PinnedPolicySnapshot $pinned = null,
        ?PolicyLayer $requestNarrowing = null,
        ?string $panelKey = null,
        ?PolicyScope $editableScope = null,
        ?string $editableScopeId = null,
        bool $bypassCache = false,
        string $panelAction = 'execute',
    ): EffectivePolicy {
        if ($trafficKey === '') {
            throw new InvalidSettingPatch;
        }

        $tenant = $this->verifyTenantContext($subjects->tenant);
        $resolvedPanelKey = $panelKey !== null && trim($panelKey) !== ''
            ? trim($panelKey)
            : $this->currentPanelKey();

        $loaded = $this->inspector->loadDatabaseLayers(
            tenant: $tenant,
            subjects: $subjects,
            targetId: $targetId,
            trafficKey: $trafficKey,
            panelKey: $resolvedPanelKey,
            panelAction: $panelAction,
        );

        $layers = $loaded['layers'];

        if ($pinned !== null) {
            if (! $pinned->tenantKey->equals($tenant)) {
                throw new AuthorizationDenied;
            }

            $layers[] = $pinned->toPolicyLayer();
        }

        if ($requestNarrowing !== null) {
            $layers[] = $requestNarrowing->kind === PolicyLayerKind::RequestNarrowing
                ? $requestNarrowing
                : new PolicyLayer(
                    kind: PolicyLayerKind::RequestNarrowing,
                    scopeKey: $requestNarrowing->scopeKey,
                    patch: $requestNarrowing->patch,
                    revision: $requestNarrowing->revision,
                    profileKey: $requestNarrowing->profileKey,
                    profileId: $requestNarrowing->profileId,
                    profileRevision: $requestNarrowing->profileRevision,
                    allowedProfileKeys: $requestNarrowing->allowedProfileKeys,
                    outcomePolicyKey: $requestNarrowing->outcomePolicyKey,
                    budgets: $requestNarrowing->budgets,
                    subject: $requestNarrowing->subject,
                );
        }

        $canUseCache = ! $bypassCache
            && $pinned === null
            && $requestNarrowing === null
            && $this->cache !== null;

        $cacheKey = null;

        if ($canUseCache) {
            /** @var PolicyResolutionCache $cache */
            $cache = $this->cache;
            $cacheKey = $cache->buildKey(
                tenant: $tenant,
                panelKey: $resolvedPanelKey,
                trafficKey: $trafficKey,
                targetId: $targetId,
                targetRevision: $loaded['target_revision'],
                subjects: $subjects,
                policyVersions: $loaded['policy_versions'],
                registryFingerprint: $this->registryFingerprint($resolvedPanelKey),
            );

            $cached = $cache->get($cacheKey);

            if ($cached !== null) {
                return $this->inspector->reattachLivePermissionExplanations(
                    policy: $cached,
                    tenant: $tenant,
                    editableScope: $editableScope ?? $loaded['default_editable_scope'],
                    editableScopeId: $editableScopeId ?? $loaded['default_editable_scope_id'],
                    editableHeadId: $loaded['editable_head_id'],
                )->withAuthorityVersions($loaded['policy_versions']);
            }
        }

        $resolvedEditableScope = $editableScope ?? $loaded['default_editable_scope'];
        $resolvedEditableScopeId = $editableScopeId ?? $loaded['default_editable_scope_id'];

        $effective = $this->resolveFromLayers(
            subjects: $subjects,
            targetId: $targetId,
            layers: $layers,
            trafficKey: $trafficKey,
            destinationPolicyKey: $loaded['destination_policy_key'],
            editableScope: $resolvedEditableScope,
            editableScopeId: $resolvedEditableScopeId,
            permissionChecker: fn (SettingDescriptor $descriptor, PolicyScope $scope, string $scopeId): bool => $this->inspector->canEditField(
                tenant: $tenant,
                descriptor: $descriptor,
                scope: $scope,
                scopeId: $scopeId,
                headId: $loaded['editable_head_id'],
            ),
            profileDirectMemberMap: $loaded['profile_direct_member_map'],
            profileIdMap: $loaded['profile_id_map'],
            profileRevisionMap: $loaded['profile_revision_map'],
        );

        if ($canUseCache) {
            /** @var PolicyResolutionCache $cache */
            $cache = $this->cache;
            /** @var string $cacheKey */
            $transactionLevel = $this->db?->transactionLevel() ?? 0;
            $cache->put($cacheKey, $effective, $tenant, $transactionLevel);
        }

        return $effective->withAuthorityVersions($loaded['policy_versions']);
    }

    /**
     * Чистое детерминированное вычисление EffectivePolicy по 10 слоям.
     *
     * @param  list<PolicyLayer>  $layers
     * @param  (Closure(SettingDescriptor, PolicyScope, string): bool)|null  $permissionChecker
     * @param  array<string, bool>  $profileDirectMemberMap
     * @param  array<string, Uuid>  $profileIdMap
     * @param  array<string, int>  $profileRevisionMap
     */
    public function resolveFromLayers(
        SubjectChain $subjects,
        Uuid $targetId,
        array $layers,
        string $trafficKey = 'default',
        string $destinationPolicyKey = 'default',
        ?PolicyScope $editableScope = null,
        ?string $editableScopeId = null,
        ?Closure $permissionChecker = null,
        array $profileDirectMemberMap = [],
        array $profileIdMap = [],
        array $profileRevisionMap = [],
    ): EffectivePolicy {
        if ($trafficKey === '') {
            throw new InvalidSettingPatch;
        }

        $registry = $this->currentRegistry();
        $orderedLayers = $this->merger->normalizeLayers($layers);
        $hardBounds = $this->resolvedHardBounds();

        $leafPolicyLayer = PolicyLayerKind::PackageDefaults;
        $leafScope = PolicyScope::Entity;
        $leafScopeId = $subjects->leaf->type.':'.$subjects->leaf->id;
        $leafScopeKey = 'package_defaults';

        foreach ($orderedLayers as $layer) {
            if (! $layer->kind->isPostBindingNarrowing()) {
                $leafPolicyLayer = $layer->kind;
                $leafScopeKey = $layer->scopeKey;
                $maybeScope = $layer->kind->policyScope();

                if ($maybeScope !== null) {
                    $leafScope = $maybeScope;
                    $leafScopeId = PolicySettingMerger::extractScopeId($layer->scopeKey, $maybeScope);
                }
            }
        }

        $targetScope = $editableScope ?? $leafScope;
        $targetScopeId = $editableScopeId ?? $leafScopeId;

        /** @var array{allowed: list<string>|null, source_scope: string, source_revision: int} $allowedProfilesState */
        $allowedProfilesState = [
            'allowed' => isset($hardBounds['allowed_profile_keys']) && is_array($hardBounds['allowed_profile_keys'])
                ? array_values(array_unique(RowValues::strList($hardBounds['allowed_profile_keys'])))
                : null,
            'source_scope' => 'package_defaults',
            'source_revision' => 1,
        ];

        $selectedProfileKey = null;
        $selectedProfileId = null;
        $selectedProfileRevision = null;
        $selectedOutcomePolicyKey = 'default';
        $layerRevisions = [];
        $appliedLayers = [];

        foreach ($orderedLayers as $layer) {
            $appliedLayers[] = $layer->kind->value.':'.$layer->scopeKey;
            $layerRevisions[$layer->kind->value.':'.$layer->scopeKey] = $layer->revision;

            if ($layer->allowedProfileKeys !== null) {
                if ($allowedProfilesState['allowed'] === null) {
                    $allowedProfilesState['allowed'] = $layer->allowedProfileKeys;
                    $allowedProfilesState['source_scope'] = $layer->scopeKey;
                    $allowedProfilesState['source_revision'] = $layer->revision;
                } else {
                    $allowedProfilesState['allowed'] = array_values(
                        array_intersect($allowedProfilesState['allowed'], $layer->allowedProfileKeys),
                    );
                }
            }

            if ($layer->profileKey !== null) {
                $allowed = $allowedProfilesState['allowed'];
                $isAllowed = $allowed === null || in_array($layer->profileKey, $allowed, true);
                $isStaleSnapshotOverride = $layer->kind === PolicyLayerKind::PinnedExecutionSnapshot
                    && $selectedProfileKey !== null
                    && $selectedProfileKey !== $layer->profileKey;

                if ($isAllowed && ! $isStaleSnapshotOverride) {
                    $selectedProfileKey = $layer->profileKey;
                    $selectedProfileId = $layer->profileId ?? ($profileIdMap[$layer->profileKey] ?? $selectedProfileId);
                    $selectedProfileRevision = $layer->profileRevision ?? ($profileRevisionMap[$layer->profileKey] ?? 1);
                }
            }

            if ($layer->outcomePolicyKey !== null) {
                $isStaleOutcome = $layer->kind === PolicyLayerKind::PinnedExecutionSnapshot
                    && $selectedOutcomePolicyKey !== 'default'
                    && $selectedOutcomePolicyKey !== $layer->outcomePolicyKey;

                if (! $isStaleOutcome) {
                    $selectedOutcomePolicyKey = $layer->outcomePolicyKey;
                }
            }
        }

        if ($selectedProfileKey !== null) {
            if ($selectedProfileId === null && isset($profileIdMap[$selectedProfileKey])) {
                $selectedProfileId = $profileIdMap[$selectedProfileKey];
            }

            if (isset($profileRevisionMap[$selectedProfileKey])) {
                $selectedProfileRevision = $profileRevisionMap[$selectedProfileKey];
            } elseif ($selectedProfileRevision === null) {
                $selectedProfileRevision = 1;
            }
        }

        /** @var array<string, mixed> $effectiveValues */
        $effectiveValues = [];
        /** @var array<string, FieldExplanation> $fieldExplanations */
        $fieldExplanations = [];
        /** @var list<string> $disabledSettings */
        $disabledSettings = [];

        foreach ($registry->all() as $descriptor) {
            $key = $descriptor->key;
            $resolvedField = $this->merger->resolveSingleDescriptor(
                descriptor: $descriptor,
                orderedLayers: $orderedLayers,
                leafPolicyLayer: $leafPolicyLayer,
                leafScopeKey: $leafScopeKey,
                targetScope: $targetScope,
                targetScopeId: $targetScopeId,
                hardBounds: $hardBounds,
                allowedProfilesState: $allowedProfilesState,
                permissionChecker: $permissionChecker,
            );

            $effectiveValues[$key] = $resolvedField['value'];
            $fieldExplanations[$key] = $resolvedField['explanation'];

            if ($resolvedField['disabled']) {
                $disabledSettings[] = $key;
            }
        }

        // Если стратегия или профиль переопределены через настройки реестра, синхронизируем с выбранным профилем.
        if (isset($effectiveValues['route.profile_key']) && is_string($effectiveValues['route.profile_key'])) {
            $selectedProfileKey = $effectiveValues['route.profile_key'];
            $selectedProfileId = $profileIdMap[$selectedProfileKey] ?? $selectedProfileId;
            $selectedProfileRevision = $profileRevisionMap[$selectedProfileKey] ?? ($selectedProfileRevision ?? 1);
        }

        // Пост-обработка кросс-полевых ограничений таймаутов: connect_ms <= total_ms и read_ms <= total_ms.
        $totalMs = RowValues::int($effectiveValues['timeout.total_ms'] ?? 10000);
        $connectMs = RowValues::int($effectiveValues['timeout.connect_ms'] ?? 1000);
        $readMs = RowValues::int($effectiveValues['timeout.read_ms'] ?? 10000);
        $totalExplanation = $fieldExplanations['timeout.total_ms'] ?? null;

        if ($connectMs > $totalMs) {
            $connectMs = $totalMs;
            $effectiveValues['timeout.connect_ms'] = $connectMs;

            if (isset($fieldExplanations['timeout.connect_ms'])) {
                $prev = $fieldExplanations['timeout.connect_ms'];
                $fieldExplanations['timeout.connect_ms'] = new FieldExplanation(
                    key: $prev->key,
                    effectiveValue: $connectMs,
                    sourceScope: $totalExplanation->sourceScope ?? $prev->sourceScope,
                    sourceLayer: $totalExplanation->sourceLayer ?? $prev->sourceLayer,
                    sourceRevision: $totalExplanation->sourceRevision ?? $prev->sourceRevision,
                    inherited: $prev->inherited,
                    explicit: $prev->explicit,
                    disabled: $prev->disabled,
                    clamped: true,
                    editable: $prev->editable,
                    permission: $prev->permission,
                    reason: 'clamped_by_total_timeout',
                    minimumBound: $prev->minimumBound,
                    maximumBound: $totalMs,
                    clampedByScope: $totalExplanation->sourceScope ?? $prev->sourceScope,
                    clampedByRevision: $totalExplanation->sourceRevision ?? $prev->sourceRevision,
                    sensitive: $prev->sensitive,
                    provenance: $prev->provenance,
                );
            }
        }

        if ($readMs > $totalMs) {
            $readMs = $totalMs;
            $effectiveValues['timeout.read_ms'] = $readMs;

            if (isset($fieldExplanations['timeout.read_ms'])) {
                $prev = $fieldExplanations['timeout.read_ms'];
                $fieldExplanations['timeout.read_ms'] = new FieldExplanation(
                    key: $prev->key,
                    effectiveValue: $readMs,
                    sourceScope: $totalExplanation->sourceScope ?? $prev->sourceScope,
                    sourceLayer: $totalExplanation->sourceLayer ?? $prev->sourceLayer,
                    sourceRevision: $totalExplanation->sourceRevision ?? $prev->sourceRevision,
                    inherited: $prev->inherited,
                    explicit: $prev->explicit,
                    disabled: $prev->disabled,
                    clamped: true,
                    editable: $prev->editable,
                    permission: $prev->permission,
                    reason: 'clamped_by_total_timeout',
                    minimumBound: $prev->minimumBound,
                    maximumBound: $totalMs,
                    clampedByScope: $totalExplanation->sourceScope ?? $prev->sourceScope,
                    clampedByRevision: $totalExplanation->sourceRevision ?? $prev->sourceRevision,
                    sensitive: $prev->sensitive,
                    provenance: $prev->provenance,
                );
            }
        }

        $retryDelaysDisabled = in_array('retry.delays_ms', $disabledSettings, true);
        /** @var list<int> $retryDelaysMs */
        $retryDelaysMs = [];

        if (! $retryDelaysDisabled && is_array($effectiveValues['retry.delays_ms'] ?? null)) {
            foreach ($effectiveValues['retry.delays_ms'] as $delayItem) {
                $retryDelaysMs[] = max(0, RowValues::int($delayItem));
            }
        }

        if ($retryDelaysDisabled) {
            $effectiveValues['retry.delays_ms'] = [];
        }

        $maxAttempts = max(1, RowValues::int($effectiveValues['retry.max_attempts'] ?? 1));
        $maxRedirects = max(0, RowValues::int($effectiveValues['http.max_redirects'] ?? 0));

        // Direct fallback требует:
        // 1) Разрешения политики (route.allow_direct === true после пересечения слоёв и hard bounds)
        // 2) Явного direct-участника в выбранном профиле (если карта профилей передана/загружена).
        $policyAllowsDirect = (bool) ($effectiveValues['route.allow_direct'] ?? false);
        $profileLookupKey = $selectedProfileKey ?? 'default';
        $profileHasDirectMember = $profileDirectMemberMap[$profileLookupKey] ?? true;
        $allowDirectRoute = $policyAllowsDirect && $profileHasDirectMember;

        if ($policyAllowsDirect && ! $profileHasDirectMember) {
            $effectiveValues['route.allow_direct'] = false;

            if (isset($fieldExplanations['route.allow_direct'])) {
                $prev = $fieldExplanations['route.allow_direct'];
                $fieldExplanations['route.allow_direct'] = new FieldExplanation(
                    key: $prev->key,
                    effectiveValue: false,
                    sourceScope: $prev->sourceScope,
                    sourceLayer: $prev->sourceLayer,
                    sourceRevision: $prev->sourceRevision,
                    inherited: $prev->inherited,
                    explicit: $prev->explicit,
                    disabled: $prev->disabled,
                    clamped: true,
                    editable: $prev->editable,
                    permission: $prev->permission,
                    reason: 'requires_explicit_direct_profile_member',
                    minimumBound: $prev->minimumBound,
                    maximumBound: $prev->maximumBound,
                    clampedByScope: $prev->sourceScope,
                    clampedByRevision: $prev->sourceRevision,
                    sensitive: $prev->sensitive,
                    provenance: $prev->provenance,
                );
            }
        }

        /** @var list<string> $deniedRoutes */
        $deniedRoutes = is_array($effectiveValues['routes.denied'] ?? null)
            ? array_values(array_unique(RowValues::strList($effectiveValues['routes.denied'])))
            : [];

        /** @var list<string>|null $allowedDestinations */
        $allowedDestinations = is_array($effectiveValues['destinations.allowed'] ?? null)
            ? array_values(array_unique(RowValues::strList($effectiveValues['destinations.allowed'])))
            : null;

        $budgets = $this->merger->resolveBudgets($orderedLayers);

        $timeouts = new RequestTimeoutPolicy(
            connectTimeoutMs: $connectMs,
            readTimeoutMs: $readMs,
            totalTimeoutMs: $totalMs,
            cleanupMarginMs: 15_000,
            maxAttempts: $maxAttempts,
            maxDistinctRoutes: $maxAttempts,
            maxRedirects: $maxRedirects,
            retryDelaysMs: $retryDelaysMs,
        );

        $strategyKey = is_string($effectiveValues['route.strategy'] ?? null)
            ? $effectiveValues['route.strategy']
            : 'uniform_random';

        $effectiveRevision = $this->merger->computeEffectiveRevision($orderedLayers);
        $effectiveValuesForSnapshot = [];

        foreach ($registry->all() as $descriptor) {
            if ($descriptor->sensitive) {
                continue;
            }

            $effectiveValuesForSnapshot[$descriptor->key] = $effectiveValues[$descriptor->key] ?? null;
        }

        $policyHash = $this->merger->computePolicyHash(
            subjects: $subjects,
            targetId: $targetId,
            trafficKey: $trafficKey,
            destinationPolicyKey: $destinationPolicyKey,
            selectedProfileKey: $selectedProfileKey,
            selectedProfileRevision: $selectedProfileRevision,
            strategyKey: $strategyKey,
            outcomePolicyKey: $selectedOutcomePolicyKey,
            effectiveValues: $effectiveValuesForSnapshot,
            disabledSettings: $disabledSettings,
            budgets: $budgets,
            layerRevisions: $layerRevisions,
        );

        return new EffectivePolicy(
            tenantKey: $subjects->tenant,
            subjectChain: $subjects,
            targetId: $targetId,
            destinationPolicyKey: $destinationPolicyKey,
            trafficKey: $trafficKey,
            routeProfileId: $selectedProfileId,
            routeProfileKey: $selectedProfileKey,
            routeProfileRevision: $selectedProfileRevision,
            allowedProfileKeys: $allowedProfilesState['allowed'],
            timeouts: $timeouts,
            retryDelaysDisabled: $retryDelaysDisabled,
            allowDirectRoute: $allowDirectRoute,
            strategyKey: $strategyKey,
            outcomePolicyKey: $selectedOutcomePolicyKey,
            deniedRoutes: $deniedRoutes,
            allowedDestinations: $allowedDestinations,
            budgets: $budgets,
            values: $effectiveValuesForSnapshot,
            disabledSettings: $disabledSettings,
            layerRevisions: $layerRevisions,
            appliedLayers: $appliedLayers,
            explanations: $fieldExplanations,
            effectiveRevision: $effectiveRevision,
            policyHash: $policyHash,
        );
    }

    /**
     * Чистый предпросмотр (preview) несохранённого патча через тот же резолвер/валидацию
     * без записи в БД, без загрязнения кэша и без резервирования квот.
     *
     * @param  list<PolicyLayer>|null  $baseLayers
     */
    public function preview(
        SubjectChain $subjects,
        Uuid $targetId,
        PolicyScope $scope,
        string $scopeId,
        SettingPatch|string $unsavedPatch,
        string $trafficKey = 'default',
        ?string $panelKey = null,
        ?array $baseLayers = null,
    ): PolicyPreview {
        if ($scopeId === '' || preg_match('/^[a-z0-9][a-z0-9_.:-]{0,127}$/D', $scopeId) !== 1) {
            throw new InvalidSettingPatch;
        }

        $patch = is_string($unsavedPatch)
            ? SettingPatch::decode($unsavedPatch)
            : $unsavedPatch;

        $registry = $this->currentRegistry();
        $registry->validate($patch, $scope);

        $tenant = $this->verifyTenantContext($subjects->tenant);
        $destinationPolicyKey = 'default';
        $profileDirectMemberMap = [];
        $profileIdMap = [];
        $profileRevisionMap = [];
        $editableHeadId = null;

        if ($baseLayers === null) {
            $resolvedPanelKey = $panelKey !== null && trim($panelKey) !== ''
                ? trim($panelKey)
                : $this->currentPanelKey();

            $loaded = $this->inspector->loadDatabaseLayers(
                tenant: $tenant,
                subjects: $subjects,
                targetId: $targetId,
                trafficKey: $trafficKey,
                panelKey: $resolvedPanelKey,
            );

            $baseLayers = $loaded['layers'];
            $destinationPolicyKey = $loaded['destination_policy_key'];
            $profileDirectMemberMap = $loaded['profile_direct_member_map'];
            $profileIdMap = $loaded['profile_id_map'];
            $profileRevisionMap = $loaded['profile_revision_map'];
            $editableHeadId = $loaded['editable_head_id'];
        }

        $permissionChecker = fn (SettingDescriptor $descriptor, PolicyScope $s, string $sid): bool => $this->inspector->canEditField(
            tenant: $tenant,
            descriptor: $descriptor,
            scope: $s,
            scopeId: $sid,
            headId: $editableHeadId,
        );

        $before = $this->resolveFromLayers(
            subjects: $subjects,
            targetId: $targetId,
            layers: $baseLayers,
            trafficKey: $trafficKey,
            destinationPolicyKey: $destinationPolicyKey,
            editableScope: $scope,
            editableScopeId: $scopeId,
            permissionChecker: $permissionChecker,
            profileDirectMemberMap: $profileDirectMemberMap,
            profileIdMap: $profileIdMap,
            profileRevisionMap: $profileRevisionMap,
        );

        $previewLayers = $this->merger->injectPreviewLayer(
            baseLayers: $baseLayers,
            scope: $scope,
            scopeId: $scopeId,
            patch: $patch,
            subjects: $subjects,
            registry: $registry,
        );

        $after = $this->resolveFromLayers(
            subjects: $subjects,
            targetId: $targetId,
            layers: $previewLayers,
            trafficKey: $trafficKey,
            destinationPolicyKey: $destinationPolicyKey,
            editableScope: $scope,
            editableScopeId: $scopeId,
            permissionChecker: $permissionChecker,
            profileDirectMemberMap: $profileDirectMemberMap,
            profileIdMap: $profileIdMap,
            profileRevisionMap: $profileRevisionMap,
        );

        $diffs = [];

        foreach ($registry->all() as $descriptor) {
            if ($descriptor->sensitive) {
                continue;
            }

            $key = $descriptor->key;
            $beforeField = $before->explanations[$key] ?? null;
            $afterField = $after->explanations[$key] ?? null;

            if ($beforeField === null || $afterField === null) {
                continue;
            }

            $wasPatched = isset($patch->changes[$key]);
            $changed = $beforeField->effectiveValue !== $afterField->effectiveValue
                || $beforeField->disabled !== $afterField->disabled
                || $beforeField->clamped !== $afterField->clamped
                || $beforeField->sourceScope !== $afterField->sourceScope
                || $beforeField->sourceRevision !== $afterField->sourceRevision;

            if (! $changed && ! $wasPatched) {
                continue;
            }

            $diffs[$key] = new PolicyFieldDiff(
                key: $key,
                beforeValue: $beforeField->effectiveValue,
                afterValue: $afterField->effectiveValue,
                beforeDisabled: $beforeField->disabled,
                afterDisabled: $afterField->disabled,
                beforeSourceScope: $beforeField->sourceScope,
                afterSourceScope: $afterField->sourceScope,
                clamped: $afterField->clamped,
                reason: $afterField->reason,
            );
        }

        return new PolicyPreview(
            targetScope: $scope,
            targetScopeId: $scopeId,
            before: $before,
            after: $after,
            diff: $diffs,
            constraints: $after->explanations,
            budgets: $after->budgets,
        );
    }

    /**
     * Авторитетная live-проверка безопасности (SQL, всегда в обход кэша).
     *
     * Возвращает типизированный исход: LivePolicyAuthority, PolicyChanged, NeedsReplan или ExecutionBlocked.
     * Никогда не выполняет скрытый откат на direct-маршрут при отказе или отзыве прокси.
     */
    public function revalidateLive(
        PinnedPolicySnapshot $pinned,
        SubjectChain $subjects,
        string $trafficKey = 'default',
        ?HealthEpochMap $pinnedHealthEpochs = null,
        ?Route $selectedRoute = null,
        ?string $panelKey = null,
    ): LivePolicyAuthority|PolicyChanged|NeedsReplan|ExecutionBlocked {
        $db = $this->requireConnection();
        $tables = PackageSchema::tables();

        try {
            $tenant = $this->verifyTenantContext($subjects->tenant);
        } catch (AuthorizationDenied) {
            return new ExecutionBlocked(
                reason: BlockedReason::CrossTenantDenied,
                safeDetails: ['detail' => 'subject_tenant_mismatch'],
            );
        }

        if (! $tenant->equals($pinned->tenantKey)) {
            return new ExecutionBlocked(
                reason: BlockedReason::CrossTenantDenied,
                safeDetails: [
                    'active_tenant' => $tenant->value,
                    'pinned_tenant' => $pinned->tenantKey->value,
                ],
            );
        }

        $targetRow = $db->table($tables['targets'])
            ->where('tenant_key', $tenant->value)
            ->where('id', $pinned->targetId->value)
            ->first();

        if ($targetRow === null || $targetRow->retired_at !== null) {
            return new ExecutionBlocked(
                reason: BlockedReason::TargetRetired,
                safeDetails: ['target_id' => $pinned->targetId->value],
            );
        }

        $target = CatalogRowMapper::target($targetRow);

        $targetHealthRow = $db->table($tables['target_health'])
            ->where('tenant_key', $tenant->value)
            ->where('target_id', $target->id->value)
            ->first();

        if ($targetHealthRow !== null && ($targetHealthRow->state === 'open' || $targetHealthRow->availability === 'unavailable')) {
            return new ExecutionBlocked(
                reason: BlockedReason::ConfigurationHold,
                safeDetails: [
                    'health_state' => RowValues::colStr($targetHealthRow, 'state'),
                    'target_id' => $target->id->value,
                ],
            );
        }

        $resolvedPanelKey = $panelKey !== null && trim($panelKey) !== ''
            ? trim($panelKey)
            : $this->currentPanelKey();

        // Живая политика вычисляется напрямую из SQL (без кэша).
        try {
            $livePolicy = $this->resolveEffective(
                subjects: $subjects,
                targetId: $pinned->targetId,
                trafficKey: $trafficKey,
                panelKey: $resolvedPanelKey,
                bypassCache: true,
            );

            // Пересекаем живую политику со слепком (устаревший слепок не восстанавливает запрещённый маршрут и не расширяет лимиты/бюджеты).
            $intersectedPolicy = $this->resolveEffective(
                subjects: $subjects,
                targetId: $pinned->targetId,
                trafficKey: $trafficKey,
                pinned: $pinned,
                panelKey: $resolvedPanelKey,
                bypassCache: true,
            );
        } catch (SubjectResolutionFailed|AuthorizationDenied) {
            return new ExecutionBlocked(reason: BlockedReason::ConfigurationHold, safeDetails: ['detail' => 'panel_subject_or_binding_unavailable']);
        }

        if (! $intersectedPolicy->isDestinationAllowed($target->destinationPolicyKey)) {
            return new ExecutionBlocked(
                reason: BlockedReason::UrlPolicyDenied,
                safeDetails: [
                    'destination_policy_key' => $target->destinationPolicyKey,
                    'target_id' => $target->id->value,
                ],
            );
        }

        // Проверяем квотные окна (quota_windows) по активным бюджетам в SQL.
        $quotaExhausted = $this->inspector->hasExhaustedLiveQuotaWindow($db, $tenant, array_keys($intersectedPolicy->budgets));

        if ($quotaExhausted !== null) {
            return new ExecutionBlocked(
                reason: BlockedReason::PolicyReview,
                safeDetails: [
                    'budget_scope_key' => $quotaExhausted,
                    'status' => 'quota_window_exhausted',
                ],
            );
        }

        $profileKeyToLoad = $pinned->routeProfileKey ?? $intersectedPolicy->routeProfileKey ?? 'default';

        if (! $livePolicy->isProfileAllowed($profileKeyToLoad)) {
            return new ExecutionBlocked(
                reason: BlockedReason::PolicyReview,
                safeDetails: [
                    'profile_key' => $profileKeyToLoad,
                    'status' => 'profile_disallowed_by_live_policy',
                ],
            );
        }

        $profileQuery = $db->table($tables['route_profiles'])
            ->where('tenant_key', $tenant->value);

        if ($pinned->routeProfileId !== null) {
            $profileQuery->where('id', $pinned->routeProfileId->value);
        } else {
            $profileQuery->where('profile_key', $profileKeyToLoad);
        }

        $profileRow = $profileQuery->first();

        if ($profileRow === null || $profileRow->retired_at !== null) {
            return new ExecutionBlocked(
                reason: BlockedReason::EmptyProfile,
                safeDetails: [
                    'profile_key' => $profileKeyToLoad,
                    'status' => 'profile_retired_or_missing',
                ],
            );
        }

        $liveProfileState = $this->inspector->inspectLiveProfileRoutes(
            tenant: $tenant,
            profileRow: $profileRow,
            target: $target,
            intersectedPolicy: $intersectedPolicy,
        );

        if ($selectedRoute !== null) {
            $selectedCheck = $this->inspector->verifySelectedRouteLive(
                tenant: $tenant,
                selectedRoute: $selectedRoute,
                pinned: $pinned,
                intersectedPolicy: $intersectedPolicy,
                liveProfileState: $liveProfileState,
            );

            if ($selectedCheck !== null) {
                return $selectedCheck;
            }
        }

        $liveProfileKey = RowValues::colStr($profileRow, 'profile_key');

        if ($liveProfileState['eligible_routes'] === []) {
            if ($liveProfileState['blocked_direct_only']) {
                return new ExecutionBlocked(
                    reason: BlockedReason::DirectRouteNotAllowed,
                    safeDetails: ['profile_key' => $liveProfileKey],
                );
            }

            return new ExecutionBlocked(
                reason: BlockedReason::EmptyProfile,
                safeDetails: ['profile_key' => $liveProfileKey],
            );
        }

        if ($pinnedHealthEpochs !== null && $pinnedHealthEpochs->isStaleComparedTo($liveProfileState['health_epochs'])) {
            $affectedRoutes = [];

            foreach ($pinnedHealthEpochs->epochs as $scopeKey => $pinnedEpoch) {
                $liveEpoch = $liveProfileState['health_epochs']->epochs[$scopeKey] ?? null;

                if ($liveEpoch !== $pinnedEpoch) {
                    $affectedRoutes[] = $scopeKey;
                }
            }

            return new NeedsReplan(
                pinned: $pinned,
                livePolicy: $intersectedPolicy,
                reason: 'health_epoch_changed',
                pinnedProfileKey: $pinned->routeProfileKey,
                liveProfileKey: $liveProfileKey,
                affectedRoutes: $affectedRoutes,
            );
        }

        $liveProfileRevision = RowValues::colInt($profileRow, 'revision');

        if (
            ($pinned->routeProfileRevision !== null && $liveProfileRevision !== $pinned->routeProfileRevision)
            || ($pinned->routeProfileKey !== null && $livePolicy->routeProfileKey !== null && $livePolicy->routeProfileKey !== $pinned->routeProfileKey)
        ) {
            return new NeedsReplan(
                pinned: $pinned,
                livePolicy: $intersectedPolicy,
                reason: 'route_profile_changed',
                pinnedProfileKey: $pinned->routeProfileKey,
                liveProfileKey: $livePolicy->routeProfileKey ?? $liveProfileKey,
            );
        }

        if (
            $livePolicy->effectiveRevision !== $pinned->effectiveRevision
            || ! $livePolicy->policyHash->equals($pinned->policyHash)
        ) {
            $drift = $this->inspector->detectPolicyDrift($pinned, $livePolicy);

            return new PolicyChanged(
                pinned: $pinned,
                livePolicy: $intersectedPolicy,
                changedFields: $drift['fields'],
                changedLayers: $drift['layers'],
                reason: 'policy_revision_changed',
            );
        }

        return new LivePolicyAuthority(
            effectivePolicy: $intersectedPolicy,
            target: $target,
            profile: $liveProfileState['profile'],
            eligibleRoutes: $liveProfileState['eligible_routes'],
            healthEpochs: $liveProfileState['health_epochs'],
            budgets: $intersectedPolicy->budgets,
        );
    }

    private function verifyTenantContext(TenantKey $subjectTenant): TenantKey
    {
        if ($this->access === null) {
            return $subjectTenant;
        }

        $probeId = $subjectTenant->isSystem()
            ? new Uuid('00000000-0000-4000-8000-000000000001')
            : PolicyScope::Tenant->authorizationId($subjectTenant, $subjectTenant->value);

        try {
            $context = $this->access->authorize($subjectTenant, 'policy.resolve', $probeId);

            return $context->tenant;
        } catch (RuntimeException $e) {
            if (! $e instanceof AuthorizationDenied) {
                throw new AuthorizationDenied;
            }

            throw $e;
        }
    }

    private function currentRegistry(): SettingRegistry
    {
        if ($this->registry !== null) {
            return $this->registry;
        }

        if ($this->registryResolver !== null) {
            return ($this->registryResolver)();
        }

        return $this->fallbackRegistry;
    }

    /**
     * @return array<string, mixed>
     */
    private function currentPolicyConfig(): array
    {
        if ($this->policyConfigResolver !== null) {
            return ($this->policyConfigResolver)();
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvedProjectDefaults(): array
    {
        if ($this->projectDefaults !== []) {
            return $this->projectDefaults;
        }

        $config = $this->currentPolicyConfig();

        return isset($config['project_defaults']) && is_array($config['project_defaults'])
            ? RowValues::assoc($config['project_defaults'])
            : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvedPanelDefaults(string $panelKey): array
    {
        if (isset($this->panelDefaults[$panelKey])) {
            return $this->panelDefaults[$panelKey];
        }

        $config = $this->currentPolicyConfig();
        $panelDefaults = isset($config['panel_defaults']) && is_array($config['panel_defaults'])
            ? ($config['panel_defaults'][$panelKey] ?? null)
            : null;

        return is_array($panelDefaults) ? RowValues::assoc($panelDefaults) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvedHardBounds(): array
    {
        if ($this->hardBounds !== []) {
            return $this->hardBounds;
        }

        $config = $this->currentPolicyConfig();

        return isset($config['hard_bounds']) && is_array($config['hard_bounds'])
            ? RowValues::assoc($config['hard_bounds'])
            : [];
    }

    private function currentPanelKey(): string
    {
        $config = $this->currentPolicyConfig();

        if (isset($config['panel_key']) && is_string($config['panel_key']) && trim($config['panel_key']) !== '') {
            return trim($config['panel_key']);
        }

        return $this->defaultPanelKey;
    }

    private function registryFingerprint(string $panelKey): string
    {
        $registryFp = $this->currentRegistry()->fingerprint();
        $configFp = hash(
            'sha256',
            json_encode([
                'project_defaults' => $this->resolvedProjectDefaults(),
                'panel_defaults' => $this->resolvedPanelDefaults($panelKey),
                'hard_bounds' => $this->resolvedHardBounds(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        return $registryFp.':'.substr($configFp, 0, 16).':'.($this->definitions?->fingerprint() ?? 'legacy');
    }

    private function requireConnection(): Connection
    {
        if ($this->db === null) {
            throw new RuntimeException('Database connection is required for live policy operations.');
        }

        return $this->db;
    }
}
