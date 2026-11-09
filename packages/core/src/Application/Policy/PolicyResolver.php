<?php

declare(strict_types=1);

namespace Proxyma\Application\Policy;

use Closure;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Contracts\PolicyResolver as PolicyResolverContract;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Catalog\RouteProfile;
use Proxyma\Domain\Catalog\Target;
use Proxyma\Domain\Execution\BlockedReason;
use Proxyma\Domain\Execution\ContentHash;
use Proxyma\Domain\Execution\ExecutionBlocked;
use Proxyma\Domain\Execution\RequestTimeoutPolicy;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\HealthEpochMap;
use Proxyma\Domain\Policy\BudgetConstraint;
use Proxyma\Domain\Policy\EffectivePolicy;
use Proxyma\Domain\Policy\EffectivePolicySnapshot;
use Proxyma\Domain\Policy\FieldExplanation;
use Proxyma\Domain\Policy\FieldProvenanceStep;
use Proxyma\Domain\Policy\InvalidSettingPatch;
use Proxyma\Domain\Policy\LivePolicyAuthority;
use Proxyma\Domain\Policy\NeedsReplan;
use Proxyma\Domain\Policy\PatchOperation;
use Proxyma\Domain\Policy\PinnedPolicySnapshot;
use Proxyma\Domain\Policy\PolicyChanged;
use Proxyma\Domain\Policy\PolicyExplanation;
use Proxyma\Domain\Policy\PolicyFieldDiff;
use Proxyma\Domain\Policy\PolicyLayer;
use Proxyma\Domain\Policy\PolicyLayerKind;
use Proxyma\Domain\Policy\PolicyPreview;
use Proxyma\Domain\Policy\PolicyScope;
use Proxyma\Domain\Policy\SettingChange;
use Proxyma\Domain\Policy\SettingDescriptor;
use Proxyma\Domain\Policy\SettingMerge;
use Proxyma\Domain\Policy\SettingPatch;
use Proxyma\Domain\Policy\SettingType;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Cache\PolicyResolutionCache;
use Proxyma\Infrastructure\Database\CatalogRowMapper;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Security\SharedRouteStorage;
use RuntimeException;

/**
 * Детерминированный 10-слойный резолвер эффективной политики (EffectivePolicy),
 * построитель объяснений (explain), предпросмотра (preview)
 * и авторитетной live-проверки безопасности (revalidateLive).
 */
final class PolicyResolver implements PolicyResolverContract
{
    private readonly SettingRegistry $fallbackRegistry;

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
    ) {
        $this->fallbackRegistry = $registry ?? new SettingRegistry(CoreSettings::descriptors());
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
    ): EffectivePolicy {
        if ($trafficKey === '') {
            throw new InvalidSettingPatch;
        }

        $tenant = $this->verifyTenantContext($subjects->tenant);
        $resolvedPanelKey = $panelKey !== null && trim($panelKey) !== ''
            ? trim($panelKey)
            : $this->currentPanelKey();

        $loaded = $this->loadDatabaseLayers(
            tenant: $tenant,
            subjects: $subjects,
            targetId: $targetId,
            trafficKey: $trafficKey,
            panelKey: $resolvedPanelKey,
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
                return $this->reattachLivePermissionExplanations(
                    policy: $cached,
                    tenant: $tenant,
                    editableScope: $editableScope ?? $loaded['default_editable_scope'],
                    editableScopeId: $editableScopeId ?? $loaded['default_editable_scope_id'],
                    editableHeadId: $loaded['editable_head_id'],
                );
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
            permissionChecker: fn (SettingDescriptor $descriptor, PolicyScope $scope, string $scopeId): bool => $this->canEditField(
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

        return $effective;
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
        $orderedLayers = $this->normalizeLayers($layers);
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
                    $leafScopeId = $this->extractScopeId($layer->scopeKey, $maybeScope);
                }
            }
        }

        $targetScope = $editableScope ?? $leafScope;
        $targetScopeId = $editableScopeId ?? $leafScopeId;

        /** @var array{allowed: list<string>|null, source_scope: string, source_revision: int} $allowedProfilesState */
        $allowedProfilesState = [
            'allowed' => isset($hardBounds['allowed_profile_keys']) && is_array($hardBounds['allowed_profile_keys'])
                ? array_values(array_unique(array_map('strval', $hardBounds['allowed_profile_keys'])))
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
            $resolvedField = $this->resolveSingleDescriptor(
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
        $totalMs = (int) ($effectiveValues['timeout.total_ms'] ?? 10000);
        $connectMs = (int) ($effectiveValues['timeout.connect_ms'] ?? 1000);
        $readMs = (int) ($effectiveValues['timeout.read_ms'] ?? 10000);
        $totalExplanation = $fieldExplanations['timeout.total_ms'] ?? null;

        if ($connectMs > $totalMs) {
            $connectMs = $totalMs;
            $effectiveValues['timeout.connect_ms'] = $connectMs;

            if (isset($fieldExplanations['timeout.connect_ms'])) {
                $prev = $fieldExplanations['timeout.connect_ms'];
                $fieldExplanations['timeout.connect_ms'] = new FieldExplanation(
                    key: $prev->key,
                    effectiveValue: $connectMs,
                    sourceScope: $totalExplanation !== null ? $totalExplanation->sourceScope : $prev->sourceScope,
                    sourceLayer: $totalExplanation !== null ? $totalExplanation->sourceLayer : $prev->sourceLayer,
                    sourceRevision: $totalExplanation !== null ? $totalExplanation->sourceRevision : $prev->sourceRevision,
                    inherited: $prev->inherited,
                    explicit: $prev->explicit,
                    disabled: $prev->disabled,
                    clamped: true,
                    editable: $prev->editable,
                    permission: $prev->permission,
                    reason: 'clamped_by_total_timeout',
                    minimumBound: $prev->minimumBound,
                    maximumBound: $totalMs,
                    clampedByScope: $totalExplanation !== null ? $totalExplanation->sourceScope : $prev->sourceScope,
                    clampedByRevision: $totalExplanation !== null ? $totalExplanation->sourceRevision : $prev->sourceRevision,
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
                    sourceScope: $totalExplanation !== null ? $totalExplanation->sourceScope : $prev->sourceScope,
                    sourceLayer: $totalExplanation !== null ? $totalExplanation->sourceLayer : $prev->sourceLayer,
                    sourceRevision: $totalExplanation !== null ? $totalExplanation->sourceRevision : $prev->sourceRevision,
                    inherited: $prev->inherited,
                    explicit: $prev->explicit,
                    disabled: $prev->disabled,
                    clamped: true,
                    editable: $prev->editable,
                    permission: $prev->permission,
                    reason: 'clamped_by_total_timeout',
                    minimumBound: $prev->minimumBound,
                    maximumBound: $totalMs,
                    clampedByScope: $totalExplanation !== null ? $totalExplanation->sourceScope : $prev->sourceScope,
                    clampedByRevision: $totalExplanation !== null ? $totalExplanation->sourceRevision : $prev->sourceRevision,
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
                $retryDelaysMs[] = max(0, (int) $delayItem);
            }
        }

        if ($retryDelaysDisabled) {
            $effectiveValues['retry.delays_ms'] = [];
        }

        $maxAttempts = max(1, (int) ($effectiveValues['retry.max_attempts'] ?? 1));
        $maxRedirects = max(0, (int) ($effectiveValues['http.max_redirects'] ?? 0));

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
            ? array_values(array_unique(array_map('strval', $effectiveValues['routes.denied'])))
            : [];

        /** @var list<string>|null $allowedDestinations */
        $allowedDestinations = is_array($effectiveValues['destinations.allowed'] ?? null)
            ? array_values(array_unique(array_map('strval', $effectiveValues['destinations.allowed'])))
            : null;

        $budgets = $this->resolveBudgets($orderedLayers);

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

        $effectiveRevision = $this->computeEffectiveRevision($orderedLayers);
        $effectiveValuesForSnapshot = [];

        foreach ($registry->all() as $descriptor) {
            if ($descriptor->sensitive) {
                continue;
            }

            $effectiveValuesForSnapshot[$descriptor->key] = $effectiveValues[$descriptor->key] ?? null;
        }

        $policyHash = $this->computePolicyHash(
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

            $loaded = $this->loadDatabaseLayers(
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

        $permissionChecker = fn (SettingDescriptor $descriptor, PolicyScope $s, string $sid): bool => $this->canEditField(
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

        $previewLayers = $this->injectPreviewLayer(
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
                    'health_state' => (string) $targetHealthRow->state,
                    'target_id' => $target->id->value,
                ],
            );
        }

        $resolvedPanelKey = $panelKey !== null && trim($panelKey) !== ''
            ? trim($panelKey)
            : $this->currentPanelKey();

        // Живая политика вычисляется напрямую из SQL (без кэша).
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
        $quotaExhausted = $this->hasExhaustedLiveQuotaWindow($db, $tenant, array_keys($intersectedPolicy->budgets));

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

        $liveProfileState = $this->inspectLiveProfileRoutes(
            tenant: $tenant,
            profileRow: $profileRow,
            target: $target,
            intersectedPolicy: $intersectedPolicy,
        );

        if ($selectedRoute !== null) {
            $selectedCheck = $this->verifySelectedRouteLive(
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

        if ($liveProfileState['eligible_routes'] === []) {
            if ($liveProfileState['blocked_direct_only']) {
                return new ExecutionBlocked(
                    reason: BlockedReason::DirectRouteNotAllowed,
                    safeDetails: ['profile_key' => (string) $profileRow->profile_key],
                );
            }

            return new ExecutionBlocked(
                reason: BlockedReason::EmptyProfile,
                safeDetails: ['profile_key' => (string) $profileRow->profile_key],
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
                liveProfileKey: (string) $profileRow->profile_key,
                affectedRoutes: $affectedRoutes,
            );
        }

        $liveProfileRevision = (int) $profileRow->revision;

        if (
            ($pinned->routeProfileRevision !== null && $liveProfileRevision !== $pinned->routeProfileRevision)
            || ($pinned->routeProfileKey !== null && $livePolicy->routeProfileKey !== null && $livePolicy->routeProfileKey !== $pinned->routeProfileKey)
        ) {
            return new NeedsReplan(
                pinned: $pinned,
                livePolicy: $intersectedPolicy,
                reason: 'route_profile_changed',
                pinnedProfileKey: $pinned->routeProfileKey,
                liveProfileKey: $livePolicy->routeProfileKey ?? (string) $profileRow->profile_key,
            );
        }

        if (
            $livePolicy->effectiveRevision !== $pinned->effectiveRevision
            || ! $livePolicy->policyHash->equals($pinned->policyHash)
        ) {
            $drift = $this->detectPolicyDrift($pinned, $livePolicy);

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

    /**
     * @param  list<PolicyLayer>  $orderedLayers
     * @param  array<string, mixed>  $hardBounds
     * @param  array{allowed: list<string>|null, source_scope: string, source_revision: int}  $allowedProfilesState
     * @param  (Closure(SettingDescriptor, PolicyScope, string): bool)|null  $permissionChecker
     * @return array{value: mixed, disabled: bool, explanation: FieldExplanation}
     */
    private function resolveSingleDescriptor(
        SettingDescriptor $descriptor,
        array $orderedLayers,
        PolicyLayerKind $leafPolicyLayer,
        string $leafScopeKey,
        PolicyScope $targetScope,
        string $targetScopeId,
        array $hardBounds,
        array $allowedProfilesState,
        ?Closure $permissionChecker,
    ): array {
        $key = $descriptor->key;

        $hardMin = $descriptor->minimum;
        $hardMax = $descriptor->maximum;

        if (isset($hardBounds[$key]) && is_array($hardBounds[$key])) {
            if (isset($hardBounds[$key]['min']) && is_int($hardBounds[$key]['min'])) {
                $hardMin = $hardMin === null ? $hardBounds[$key]['min'] : max($hardMin, $hardBounds[$key]['min']);
            }

            if (isset($hardBounds[$key]['max']) && is_int($hardBounds[$key]['max'])) {
                $hardMax = $hardMax === null ? $hardBounds[$key]['max'] : min($hardMax, $hardBounds[$key]['max']);
            }
        }

        $currentValue = $descriptor->default;
        $sourceScope = 'package_defaults';
        $sourceLayer = PolicyLayerKind::PackageDefaults;
        $sourceRevision = 1;
        $hasExplicitPolicyValue = false;
        $disabled = false;
        $clamped = false;
        $clampedByScope = null;
        $clampedByRevision = null;
        $reason = 'package_default';
        $upperMaxBound = $hardMax;
        $upperMinBound = $hardMin;
        $lockedByUpperScope = false;

        /** @var list<FieldProvenanceStep> $provenance */
        $provenance = [];

        /** @var array<string, array{value: mixed, scope: string, layer: PolicyLayerKind, revision: int, explicit: bool, disabled: bool}> $snapshotsBeforeScopeKey */
        $snapshotsBeforeScopeKey = [];

        foreach ($orderedLayers as $layer) {
            $scopeTrackKey = $layer->kind->value.':'.$layer->scopeKey;

            if (! isset($snapshotsBeforeScopeKey[$scopeTrackKey])) {
                $snapshotsBeforeScopeKey[$scopeTrackKey] = [
                    'value' => $currentValue,
                    'scope' => $sourceScope,
                    'layer' => $sourceLayer,
                    'revision' => $sourceRevision,
                    'explicit' => $hasExplicitPolicyValue,
                    'disabled' => $disabled,
                ];
            }

            $change = $layer->patch->changes[$key] ?? null;

            if ($layer->kind === PolicyLayerKind::PackageDefaults && $change === null) {
                $provenance[] = new FieldProvenanceStep(
                    layerKind: $layer->kind,
                    scopeKey: $layer->scopeKey,
                    revision: $layer->revision,
                    operation: 'default',
                    inputValue: $descriptor->default,
                    effectiveValueAfter: $currentValue,
                    clamped: false,
                    reason: 'package_default',
                    sensitive: $descriptor->sensitive,
                );

                continue;
            }

            if ($change === null) {
                continue;
            }

            $layerIsAboveTarget = $this->isLayerStrictlyAboveScope($layer, $targetScope, $targetScopeId);

            if ($change->operation === PatchOperation::Inherit) {
                $prior = $snapshotsBeforeScopeKey[$scopeTrackKey];
                $currentValue = $prior['value'];
                $sourceScope = $prior['scope'];
                $sourceLayer = $prior['layer'];
                $sourceRevision = $prior['revision'];
                $hasExplicitPolicyValue = $prior['explicit'];
                $disabled = $prior['disabled'];
                $reason = 'inherited';

                $provenance[] = new FieldProvenanceStep(
                    layerKind: $layer->kind,
                    scopeKey: $layer->scopeKey,
                    revision: $layer->revision,
                    operation: 'inherit',
                    inputValue: null,
                    effectiveValueAfter: $currentValue,
                    clamped: false,
                    reason: 'inherited',
                    sensitive: $descriptor->sensitive,
                );

                continue;
            }

            if ($change->operation === PatchOperation::Disable) {
                $stepResult = $this->applyDisableChange(
                    descriptor: $descriptor,
                    layer: $layer,
                    currentValue: $currentValue,
                    sourceScope: $sourceScope,
                    sourceLayer: $sourceLayer,
                    sourceRevision: $sourceRevision,
                    hasExplicitPolicyValue: $hasExplicitPolicyValue,
                    disabled: $disabled,
                );

                $currentValue = $stepResult['value'];
                $sourceScope = $stepResult['scope'];
                $sourceLayer = $stepResult['layer'];
                $sourceRevision = $stepResult['revision'];
                $hasExplicitPolicyValue = $stepResult['explicit'];
                $disabled = $stepResult['disabled'];

                if ($stepResult['clamped']) {
                    $clamped = true;
                    $clampedByScope = $stepResult['clamped_by_scope'];
                    $clampedByRevision = $stepResult['clamped_by_revision'];
                }

                $reason = $stepResult['reason'];

                if ($layerIsAboveTarget && $disabled) {
                    $lockedByUpperScope = true;
                }

                $provenance[] = new FieldProvenanceStep(
                    layerKind: $layer->kind,
                    scopeKey: $layer->scopeKey,
                    revision: $layer->revision,
                    operation: 'disable',
                    inputValue: null,
                    effectiveValueAfter: $currentValue,
                    clamped: $stepResult['clamped'],
                    reason: $stepResult['reason'],
                    sensitive: $descriptor->sensitive,
                );

                continue;
            }

            // PatchOperation::Set
            $stepResult = $this->applySetChange(
                descriptor: $descriptor,
                layer: $layer,
                newValue: $change->value,
                currentValue: $currentValue,
                sourceScope: $sourceScope,
                sourceLayer: $sourceLayer,
                sourceRevision: $sourceRevision,
                hasExplicitPolicyValue: $hasExplicitPolicyValue,
                disabled: $disabled,
                hardMin: $hardMin,
                hardMax: $hardMax,
                hardBounds: $hardBounds,
                allowedProfilesState: $allowedProfilesState,
            );

            $currentValue = $stepResult['value'];
            $sourceScope = $stepResult['scope'];
            $sourceLayer = $stepResult['layer'];
            $sourceRevision = $stepResult['revision'];
            $hasExplicitPolicyValue = $stepResult['explicit'];
            $disabled = $stepResult['disabled'];

            if ($stepResult['clamped']) {
                $clamped = true;
                $clampedByScope = $stepResult['clamped_by_scope'];
                $clampedByRevision = $stepResult['clamped_by_revision'];
            }

            $reason = $stepResult['reason'];

            if ($layerIsAboveTarget && $descriptor->merge === SettingMerge::Minimum && is_int($currentValue)) {
                $upperMaxBound = $upperMaxBound === null ? $currentValue : min($upperMaxBound, $currentValue);

                if ($hardMin !== null && $currentValue <= $hardMin) {
                    $lockedByUpperScope = true;
                }
            }

            if ($layerIsAboveTarget && $key === 'route.allow_direct' && $currentValue === false && $hasExplicitPolicyValue) {
                $lockedByUpperScope = true;
            }

            if ($layerIsAboveTarget && $descriptor->merge === SettingMerge::Intersection && is_array($currentValue) && $currentValue === []) {
                $lockedByUpperScope = true;
            }

            $provenance[] = new FieldProvenanceStep(
                layerKind: $layer->kind,
                scopeKey: $layer->scopeKey,
                revision: $layer->revision,
                operation: 'set',
                inputValue: $change->value,
                effectiveValueAfter: $currentValue,
                clamped: $stepResult['clamped'],
                reason: $stepResult['reason'],
                sensitive: $descriptor->sensitive,
            );
        }

        if ($key === 'route.allow_direct' && ($hardBounds['route.allow_direct'] ?? null) === false) {
            $lockedByUpperScope = true;
        }

        $scopeAllowed = in_array($targetScope, $descriptor->allowedScopes, true);
        $hasPermission = $scopeAllowed && ($permissionChecker !== null
            ? $permissionChecker($descriptor, $targetScope, $targetScopeId)
            : true);

        $editable = ! $descriptor->sensitive && $hasPermission && ! $lockedByUpperScope;

        if ($descriptor->sensitive) {
            $reason = 'redacted_sensitive_field';
        } elseif (! $scopeAllowed) {
            $reason = 'scope_not_allowed';
        } elseif (! $hasPermission) {
            $reason = 'locked_by_permission';
        } elseif ($lockedByUpperScope && ! $clamped) {
            $reason = 'locked_by_upper_bound';
        }

        $inherited = ! $hasExplicitPolicyValue
            || ($sourceLayer !== $leafPolicyLayer && $sourceScope !== $leafScopeKey);

        $explanation = new FieldExplanation(
            key: $key,
            effectiveValue: $descriptor->sensitive ? null : $currentValue,
            sourceScope: $sourceScope,
            sourceLayer: $sourceLayer,
            sourceRevision: max(1, $sourceRevision),
            inherited: $inherited,
            explicit: $hasExplicitPolicyValue,
            disabled: $disabled,
            clamped: $clamped,
            editable: $editable,
            permission: $descriptor->permission,
            reason: $reason,
            minimumBound: $upperMinBound,
            maximumBound: $upperMaxBound,
            clampedByScope: $clampedByScope,
            clampedByRevision: $clampedByRevision,
            sensitive: $descriptor->sensitive,
            provenance: $provenance,
        );

        return [
            'value' => $currentValue,
            'disabled' => $disabled,
            'explanation' => $explanation,
        ];
    }

    /**
     * @return array{
     *     value: mixed,
     *     scope: string,
     *     layer: PolicyLayerKind,
     *     revision: int,
     *     explicit: bool,
     *     disabled: bool,
     *     clamped: bool,
     *     clamped_by_scope: ?string,
     *     clamped_by_revision: ?int,
     *     reason: string
     * }
     */
    private function applyDisableChange(
        SettingDescriptor $descriptor,
        PolicyLayer $layer,
        mixed $currentValue,
        string $sourceScope,
        PolicyLayerKind $sourceLayer,
        int $sourceRevision,
        bool $hasExplicitPolicyValue,
        bool $disabled,
    ): array {
        if (! $descriptor->optional && $descriptor->key !== 'route.allow_direct') {
            return [
                'value' => $currentValue,
                'scope' => $sourceScope,
                'layer' => $sourceLayer,
                'revision' => $sourceRevision,
                'explicit' => $hasExplicitPolicyValue,
                'disabled' => $disabled,
                'clamped' => true,
                'clamped_by_scope' => $sourceScope,
                'clamped_by_revision' => $sourceRevision,
                'reason' => 'disable_not_supported',
            ];
        }

        $disabledValue = match ($descriptor->type) {
            SettingType::Boolean => false,
            SettingType::Integer => 0,
            SettingType::IntegerList, SettingType::IdentifierList => [],
            SettingType::Identifier => $descriptor->default,
        };

        return [
            'value' => $disabledValue,
            'scope' => $layer->scopeKey,
            'layer' => $layer->kind,
            'revision' => $layer->revision,
            'explicit' => true,
            'disabled' => true,
            'clamped' => false,
            'clamped_by_scope' => null,
            'clamped_by_revision' => null,
            'reason' => 'explicitly_disabled',
        ];
    }

    /**
     * @param  array<string, mixed>  $hardBounds
     * @param  array{allowed: list<string>|null, source_scope: string, source_revision: int}  $allowedProfilesState
     * @return array{
     *     value: mixed,
     *     scope: string,
     *     layer: PolicyLayerKind,
     *     revision: int,
     *     explicit: bool,
     *     disabled: bool,
     *     clamped: bool,
     *     clamped_by_scope: ?string,
     *     clamped_by_revision: ?int,
     *     reason: string
     * }
     */
    private function applySetChange(
        SettingDescriptor $descriptor,
        PolicyLayer $layer,
        mixed $newValue,
        mixed $currentValue,
        string $sourceScope,
        PolicyLayerKind $sourceLayer,
        int $sourceRevision,
        bool $hasExplicitPolicyValue,
        bool $disabled,
        ?int $hardMin,
        ?int $hardMax,
        array $hardBounds,
        array $allowedProfilesState,
    ): array {
        $key = $descriptor->key;

        // Если верхний слой явно отключил настройку (например retry.delays_ms или route.allow_direct),
        // нижний слой не может включить её обратно.
        if ($disabled) {
            return [
                'value' => $currentValue,
                'scope' => $sourceScope,
                'layer' => $sourceLayer,
                'revision' => $sourceRevision,
                'explicit' => $hasExplicitPolicyValue,
                'disabled' => true,
                'clamped' => true,
                'clamped_by_scope' => $sourceScope,
                'clamped_by_revision' => $sourceRevision,
                'reason' => 'clamped_by_disabled_upper_scope',
            ];
        }

        // Специальные правила из docs/policy.md для retry.delays_ms (min or explicit disable)
        if ($key === 'retry.delays_ms') {
            return $this->applyRetryDelaysMerge(
                layer: $layer,
                newValue: $newValue,
                currentValue: $currentValue,
                sourceScope: $sourceScope,
                sourceLayer: $sourceLayer,
                sourceRevision: $sourceRevision,
                hasExplicitPolicyValue: $hasExplicitPolicyValue,
                hardMin: $hardMin,
                hardMax: $hardMax,
            );
        }

        // Специальное правило из docs/policy.md для route.allow_direct (explicit profile member + hard bound)
        if ($key === 'route.allow_direct') {
            return $this->applyAllowDirectMerge(
                layer: $layer,
                newValue: (bool) $newValue,
                currentValue: (bool) $currentValue,
                sourceScope: $sourceScope,
                sourceLayer: $sourceLayer,
                sourceRevision: $sourceRevision,
                hasExplicitPolicyValue: $hasExplicitPolicyValue,
                hardBounds: $hardBounds,
            );
        }

        return match ($descriptor->merge) {
            SettingMerge::Replace => $this->applyReplaceMerge(
                key: $key,
                layer: $layer,
                newValue: $newValue,
                currentValue: $currentValue,
                sourceScope: $sourceScope,
                sourceLayer: $sourceLayer,
                sourceRevision: $sourceRevision,
                hasExplicitPolicyValue: $hasExplicitPolicyValue,
                allowedProfilesState: $allowedProfilesState,
            ),
            SettingMerge::Minimum => $this->applyMinimumMerge(
                layer: $layer,
                newValue: $newValue,
                currentValue: $currentValue,
                sourceScope: $sourceScope,
                sourceLayer: $sourceLayer,
                sourceRevision: $sourceRevision,
                hasExplicitPolicyValue: $hasExplicitPolicyValue,
                hardMin: $hardMin,
                hardMax: $hardMax,
            ),
            SettingMerge::Union => $this->applyUnionMerge(
                layer: $layer,
                newValue: $newValue,
                currentValue: $currentValue,
                sourceScope: $sourceScope,
                sourceLayer: $sourceLayer,
                sourceRevision: $sourceRevision,
                hasExplicitPolicyValue: $hasExplicitPolicyValue,
            ),
            SettingMerge::Intersection => $this->applyIntersectionMerge(
                layer: $layer,
                newValue: $newValue,
                currentValue: $currentValue,
                sourceScope: $sourceScope,
                sourceLayer: $sourceLayer,
                sourceRevision: $sourceRevision,
                hasExplicitPolicyValue: $hasExplicitPolicyValue,
            ),
        };
    }

    /**
     * @return array{
     *     value: mixed,
     *     scope: string,
     *     layer: PolicyLayerKind,
     *     revision: int,
     *     explicit: bool,
     *     disabled: bool,
     *     clamped: bool,
     *     clamped_by_scope: ?string,
     *     clamped_by_revision: ?int,
     *     reason: string
     * }
     */
    private function applyRetryDelaysMerge(
        PolicyLayer $layer,
        mixed $newValue,
        mixed $currentValue,
        string $sourceScope,
        PolicyLayerKind $sourceLayer,
        int $sourceRevision,
        bool $hasExplicitPolicyValue,
        ?int $hardMin,
        ?int $hardMax,
    ): array {
        $incoming = [];

        if (is_array($newValue)) {
            foreach ($newValue as $item) {
                $val = (int) $item;

                if ($hardMin !== null && $val < $hardMin) {
                    $val = $hardMin;
                }

                if ($hardMax !== null && $val > $hardMax) {
                    $val = $hardMax;
                }

                $incoming[] = $val;
            }
        }

        if (! $hasExplicitPolicyValue && ! $layer->kind->isPostBindingNarrowing()) {
            return [
                'value' => $incoming,
                'scope' => $layer->scopeKey,
                'layer' => $layer->kind,
                'revision' => $layer->revision,
                'explicit' => true,
                'disabled' => false,
                'clamped' => false,
                'clamped_by_scope' => null,
                'clamped_by_revision' => null,
                'reason' => 'explicit_override',
            ];
        }

        $currentList = is_array($currentValue) ? array_values(array_map('intval', $currentValue)) : [];

        if ($currentList === [] && ! $hasExplicitPolicyValue) {
            return [
                'value' => [],
                'scope' => $sourceScope,
                'layer' => $sourceLayer,
                'revision' => $sourceRevision,
                'explicit' => false,
                'disabled' => false,
                'clamped' => $incoming !== [],
                'clamped_by_scope' => $incoming !== [] ? $sourceScope : null,
                'clamped_by_revision' => $incoming !== [] ? $sourceRevision : null,
                'reason' => $incoming !== [] ? 'clamped_by_upper_bound' : 'package_default',
            ];
        }

        $merged = [];
        $wasClamped = false;
        $narrowed = false;
        $limitCount = min(count($currentList), count($incoming));

        if (count($incoming) > count($currentList)) {
            $wasClamped = true;
        } elseif (count($incoming) < count($currentList)) {
            $narrowed = true;
        }

        for ($i = 0; $i < $limitCount; $i++) {
            if ($incoming[$i] > $currentList[$i]) {
                $merged[] = $currentList[$i];
                $wasClamped = true;
            } elseif ($incoming[$i] < $currentList[$i]) {
                $merged[] = $incoming[$i];
                $narrowed = true;
            } else {
                $merged[] = $currentList[$i];
            }
        }

        return [
            'value' => $merged,
            'scope' => $narrowed ? $layer->scopeKey : $sourceScope,
            'layer' => $narrowed ? $layer->kind : $sourceLayer,
            'revision' => $narrowed ? $layer->revision : $sourceRevision,
            'explicit' => true,
            'disabled' => false,
            'clamped' => $wasClamped,
            'clamped_by_scope' => $wasClamped ? $sourceScope : null,
            'clamped_by_revision' => $wasClamped ? $sourceRevision : null,
            'reason' => $wasClamped
                ? 'clamped_by_upper_bound'
                : ($narrowed ? 'narrowed_by_lower_scope' : 'confirmed_upper_bound'),
        ];
    }

    /**
     * @param  array<string, mixed>  $hardBounds
     * @return array{
     *     value: mixed,
     *     scope: string,
     *     layer: PolicyLayerKind,
     *     revision: int,
     *     explicit: bool,
     *     disabled: bool,
     *     clamped: bool,
     *     clamped_by_scope: ?string,
     *     clamped_by_revision: ?int,
     *     reason: string
     * }
     */
    private function applyAllowDirectMerge(
        PolicyLayer $layer,
        bool $newValue,
        bool $currentValue,
        string $sourceScope,
        PolicyLayerKind $sourceLayer,
        int $sourceRevision,
        bool $hasExplicitPolicyValue,
        array $hardBounds,
    ): array {
        if (($hardBounds['route.allow_direct'] ?? null) === false && $newValue) {
            return [
                'value' => false,
                'scope' => 'package_defaults',
                'layer' => PolicyLayerKind::PackageDefaults,
                'revision' => 1,
                'explicit' => true,
                'disabled' => false,
                'clamped' => true,
                'clamped_by_scope' => 'package_defaults',
                'clamped_by_revision' => 1,
                'reason' => 'clamped_by_hard_bound',
            ];
        }

        if (! $hasExplicitPolicyValue && ! $layer->kind->isPostBindingNarrowing()) {
            return [
                'value' => $newValue,
                'scope' => $layer->scopeKey,
                'layer' => $layer->kind,
                'revision' => $layer->revision,
                'explicit' => true,
                'disabled' => false,
                'clamped' => false,
                'clamped_by_scope' => null,
                'clamped_by_revision' => null,
                'reason' => 'explicit_override',
            ];
        }

        if (! $currentValue && $newValue) {
            return [
                'value' => false,
                'scope' => $sourceScope,
                'layer' => $sourceLayer,
                'revision' => $sourceRevision,
                'explicit' => true,
                'disabled' => false,
                'clamped' => true,
                'clamped_by_scope' => $sourceScope,
                'clamped_by_revision' => $sourceRevision,
                'reason' => $layer->kind === PolicyLayerKind::PinnedExecutionSnapshot
                    ? 'clamped_stale_snapshot_direct_route'
                    : 'clamped_by_upper_bound',
            ];
        }

        if ($currentValue && ! $newValue) {
            return [
                'value' => false,
                'scope' => $layer->scopeKey,
                'layer' => $layer->kind,
                'revision' => $layer->revision,
                'explicit' => true,
                'disabled' => false,
                'clamped' => false,
                'clamped_by_scope' => null,
                'clamped_by_revision' => null,
                'reason' => 'narrowed_by_lower_scope',
            ];
        }

        return [
            'value' => $currentValue,
            'scope' => $sourceScope,
            'layer' => $sourceLayer,
            'revision' => $sourceRevision,
            'explicit' => true,
            'disabled' => false,
            'clamped' => false,
            'clamped_by_scope' => null,
            'clamped_by_revision' => null,
            'reason' => 'confirmed_upper_bound',
        ];
    }

    /**
     * @param  array{allowed: list<string>|null, source_scope: string, source_revision: int}  $allowedProfilesState
     * @return array{
     *     value: mixed,
     *     scope: string,
     *     layer: PolicyLayerKind,
     *     revision: int,
     *     explicit: bool,
     *     disabled: bool,
     *     clamped: bool,
     *     clamped_by_scope: ?string,
     *     clamped_by_revision: ?int,
     *     reason: string
     * }
     */
    private function applyReplaceMerge(
        string $key,
        PolicyLayer $layer,
        mixed $newValue,
        mixed $currentValue,
        string $sourceScope,
        PolicyLayerKind $sourceLayer,
        int $sourceRevision,
        bool $hasExplicitPolicyValue,
        array $allowedProfilesState,
    ): array {
        if ($key === 'route.profile_key' && is_string($newValue)) {
            $allowed = $allowedProfilesState['allowed'];

            if ($allowed !== null && ! in_array($newValue, $allowed, true)) {
                return [
                    'value' => $currentValue,
                    'scope' => $sourceScope,
                    'layer' => $sourceLayer,
                    'revision' => $sourceRevision,
                    'explicit' => $hasExplicitPolicyValue,
                    'disabled' => false,
                    'clamped' => true,
                    'clamped_by_scope' => $allowedProfilesState['source_scope'],
                    'clamped_by_revision' => $allowedProfilesState['source_revision'],
                    'reason' => 'clamped_by_allowed_profiles',
                ];
            }
        }

        if (
            $layer->kind === PolicyLayerKind::PinnedExecutionSnapshot
            && $hasExplicitPolicyValue
            && $newValue !== $currentValue
        ) {
            return [
                'value' => $currentValue,
                'scope' => $sourceScope,
                'layer' => $sourceLayer,
                'revision' => $sourceRevision,
                'explicit' => true,
                'disabled' => false,
                'clamped' => true,
                'clamped_by_scope' => $sourceScope,
                'clamped_by_revision' => $sourceRevision,
                'reason' => 'clamped_stale_snapshot_value',
            ];
        }

        return [
            'value' => $newValue,
            'scope' => $layer->scopeKey,
            'layer' => $layer->kind,
            'revision' => $layer->revision,
            'explicit' => true,
            'disabled' => false,
            'clamped' => false,
            'clamped_by_scope' => null,
            'clamped_by_revision' => null,
            'reason' => 'explicit_override',
        ];
    }

    /**
     * @return array{
     *     value: mixed,
     *     scope: string,
     *     layer: PolicyLayerKind,
     *     revision: int,
     *     explicit: bool,
     *     disabled: bool,
     *     clamped: bool,
     *     clamped_by_scope: ?string,
     *     clamped_by_revision: ?int,
     *     reason: string
     * }
     */
    private function applyMinimumMerge(
        PolicyLayer $layer,
        mixed $newValue,
        mixed $currentValue,
        string $sourceScope,
        PolicyLayerKind $sourceLayer,
        int $sourceRevision,
        bool $hasExplicitPolicyValue,
        ?int $hardMin,
        ?int $hardMax,
    ): array {
        if ($newValue === null) {
            if ($hasExplicitPolicyValue && $currentValue !== null) {
                return [
                    'value' => $currentValue,
                    'scope' => $sourceScope,
                    'layer' => $sourceLayer,
                    'revision' => $sourceRevision,
                    'explicit' => true,
                    'disabled' => false,
                    'clamped' => true,
                    'clamped_by_scope' => $sourceScope,
                    'clamped_by_revision' => $sourceRevision,
                    'reason' => 'clamped_by_upper_bound',
                ];
            }

            return [
                'value' => null,
                'scope' => $layer->scopeKey,
                'layer' => $layer->kind,
                'revision' => $layer->revision,
                'explicit' => true,
                'disabled' => false,
                'clamped' => false,
                'clamped_by_scope' => null,
                'clamped_by_revision' => null,
                'reason' => 'explicit_null',
            ];
        }

        $numericValue = (int) $newValue;
        $clampedByHardBound = false;

        if ($hardMax !== null && $numericValue > $hardMax) {
            $numericValue = $hardMax;
            $clampedByHardBound = true;
        }

        if ($hardMin !== null && $numericValue < $hardMin) {
            $numericValue = $hardMin;
            $clampedByHardBound = true;
        }

        if (! $hasExplicitPolicyValue && ! $layer->kind->isPostBindingNarrowing()) {
            return [
                'value' => $numericValue,
                'scope' => $layer->scopeKey,
                'layer' => $layer->kind,
                'revision' => $layer->revision,
                'explicit' => true,
                'disabled' => false,
                'clamped' => $clampedByHardBound,
                'clamped_by_scope' => $clampedByHardBound ? 'package_defaults' : null,
                'clamped_by_revision' => $clampedByHardBound ? 1 : null,
                'reason' => $clampedByHardBound ? 'clamped_by_hard_bound' : 'explicit_override',
            ];
        }

        if ($currentValue === null || $numericValue < (int) $currentValue) {
            return [
                'value' => $numericValue,
                'scope' => $layer->scopeKey,
                'layer' => $layer->kind,
                'revision' => $layer->revision,
                'explicit' => true,
                'disabled' => false,
                'clamped' => $clampedByHardBound,
                'clamped_by_scope' => $clampedByHardBound ? 'package_defaults' : null,
                'clamped_by_revision' => $clampedByHardBound ? 1 : null,
                'reason' => $clampedByHardBound
                    ? 'clamped_by_hard_bound'
                    : ($layer->kind->isPostBindingNarrowing() ? 'narrowed_by_execution_layer' : 'narrowed_by_lower_scope'),
            ];
        }

        if ($numericValue > (int) $currentValue) {
            return [
                'value' => (int) $currentValue,
                'scope' => $sourceScope,
                'layer' => $sourceLayer,
                'revision' => $sourceRevision,
                'explicit' => true,
                'disabled' => false,
                'clamped' => true,
                'clamped_by_scope' => $sourceScope,
                'clamped_by_revision' => $sourceRevision,
                'reason' => $layer->kind === PolicyLayerKind::PinnedExecutionSnapshot
                    ? 'clamped_stale_snapshot_limit'
                    : 'clamped_by_upper_bound',
            ];
        }

        return [
            'value' => (int) $currentValue,
            'scope' => $sourceScope,
            'layer' => $sourceLayer,
            'revision' => $sourceRevision,
            'explicit' => true,
            'disabled' => false,
            'clamped' => $clampedByHardBound,
            'clamped_by_scope' => $clampedByHardBound ? 'package_defaults' : null,
            'clamped_by_revision' => $clampedByHardBound ? 1 : null,
            'reason' => $clampedByHardBound ? 'clamped_by_hard_bound' : 'confirmed_upper_bound',
        ];
    }

    /**
     * @return array{
     *     value: mixed,
     *     scope: string,
     *     layer: PolicyLayerKind,
     *     revision: int,
     *     explicit: bool,
     *     disabled: bool,
     *     clamped: bool,
     *     clamped_by_scope: ?string,
     *     clamped_by_revision: ?int,
     *     reason: string
     * }
     */
    private function applyUnionMerge(
        PolicyLayer $layer,
        mixed $newValue,
        mixed $currentValue,
        string $sourceScope,
        PolicyLayerKind $sourceLayer,
        int $sourceRevision,
        bool $hasExplicitPolicyValue,
    ): array {
        $currentList = is_array($currentValue)
            ? array_values(array_unique(array_map('strval', $currentValue)))
            : [];

        $incomingList = is_array($newValue)
            ? array_values(array_unique(array_map('strval', $newValue)))
            : [];

        $merged = array_values(array_unique([...$currentList, ...$incomingList]));
        $addedNewItems = array_diff($incomingList, $currentList) !== [];
        $omittedExistingItems = $currentList !== [] && array_diff($currentList, $incomingList) !== [];

        if ($layer->kind === PolicyLayerKind::PinnedExecutionSnapshot && $omittedExistingItems) {
            return [
                'value' => $merged,
                'scope' => $addedNewItems ? $layer->scopeKey : $sourceScope,
                'layer' => $addedNewItems ? $layer->kind : $sourceLayer,
                'revision' => $addedNewItems ? $layer->revision : $sourceRevision,
                'explicit' => true,
                'disabled' => false,
                'clamped' => true,
                'clamped_by_scope' => $sourceScope,
                'clamped_by_revision' => $sourceRevision,
                'reason' => 'stale_snapshot_cannot_restore_denied_route',
            ];
        }

        if ($addedNewItems || ! $hasExplicitPolicyValue) {
            return [
                'value' => $merged,
                'scope' => $layer->scopeKey,
                'layer' => $layer->kind,
                'revision' => $layer->revision,
                'explicit' => true,
                'disabled' => false,
                'clamped' => false,
                'clamped_by_scope' => null,
                'clamped_by_revision' => null,
                'reason' => $currentList === [] ? 'explicit_override' : 'expanded_deny_union',
            ];
        }

        return [
            'value' => $merged,
            'scope' => $sourceScope,
            'layer' => $sourceLayer,
            'revision' => $sourceRevision,
            'explicit' => true,
            'disabled' => false,
            'clamped' => $omittedExistingItems,
            'clamped_by_scope' => $omittedExistingItems ? $sourceScope : null,
            'clamped_by_revision' => $omittedExistingItems ? $sourceRevision : null,
            'reason' => $omittedExistingItems ? 'preserved_upper_deny_union' : 'unchanged_union',
        ];
    }

    /**
     * @return array{
     *     value: mixed,
     *     scope: string,
     *     layer: PolicyLayerKind,
     *     revision: int,
     *     explicit: bool,
     *     disabled: bool,
     *     clamped: bool,
     *     clamped_by_scope: ?string,
     *     clamped_by_revision: ?int,
     *     reason: string
     * }
     */
    private function applyIntersectionMerge(
        PolicyLayer $layer,
        mixed $newValue,
        mixed $currentValue,
        string $sourceScope,
        PolicyLayerKind $sourceLayer,
        int $sourceRevision,
        bool $hasExplicitPolicyValue,
    ): array {
        if ($newValue === null) {
            if (is_array($currentValue)) {
                return [
                    'value' => $currentValue,
                    'scope' => $sourceScope,
                    'layer' => $sourceLayer,
                    'revision' => $sourceRevision,
                    'explicit' => true,
                    'disabled' => false,
                    'clamped' => true,
                    'clamped_by_scope' => $sourceScope,
                    'clamped_by_revision' => $sourceRevision,
                    'reason' => 'clamped_by_intersection',
                ];
            }

            return [
                'value' => null,
                'scope' => $layer->scopeKey,
                'layer' => $layer->kind,
                'revision' => $layer->revision,
                'explicit' => true,
                'disabled' => false,
                'clamped' => false,
                'clamped_by_scope' => null,
                'clamped_by_revision' => null,
                'reason' => 'unrestricted_intersection',
            ];
        }

        $incomingList = is_array($newValue)
            ? array_values(array_unique(array_map('strval', $newValue)))
            : [];

        if ($currentValue === null) {
            return [
                'value' => $incomingList,
                'scope' => $layer->scopeKey,
                'layer' => $layer->kind,
                'revision' => $layer->revision,
                'explicit' => true,
                'disabled' => false,
                'clamped' => false,
                'clamped_by_scope' => null,
                'clamped_by_revision' => null,
                'reason' => 'explicit_override',
            ];
        }

        $currentList = is_array($currentValue) ? $currentValue : [];
        $intersection = array_values(array_intersect($currentList, $incomingList));
        $attemptedOutOfBounds = array_diff($incomingList, $currentList) !== [];
        $narrowedFurther = count($intersection) < count($currentList);

        return [
            'value' => $intersection,
            'scope' => $narrowedFurther ? $layer->scopeKey : $sourceScope,
            'layer' => $narrowedFurther ? $layer->kind : $sourceLayer,
            'revision' => $narrowedFurther ? $layer->revision : $sourceRevision,
            'explicit' => true,
            'disabled' => false,
            'clamped' => $attemptedOutOfBounds,
            'clamped_by_scope' => $attemptedOutOfBounds ? $sourceScope : null,
            'clamped_by_revision' => $attemptedOutOfBounds ? $sourceRevision : null,
            'reason' => $attemptedOutOfBounds ? 'clamped_by_intersection' : 'narrowed_by_intersection',
        ];
    }

    /**
     * Все активные бюджеты по разным scopeKey действуют одновременно;
     * бюджеты с одинаковым scopeKey пересекаются через BudgetConstraint::intersect()
     * (max(emissionIntervalUs), min(burst), min(maxInflight)), поэтому устаревший слепок
     * никогда не увеличивает бюджет.
     *
     * @param  list<PolicyLayer>  $orderedLayers
     * @return array<string, BudgetConstraint>
     */
    private function resolveBudgets(array $orderedLayers): array
    {
        /** @var array<string, BudgetConstraint> $byScopeKey */
        $byScopeKey = [];

        foreach ($orderedLayers as $layer) {
            foreach ($layer->budgets as $budget) {
                $byScopeKey[$budget->scopeKey] = isset($byScopeKey[$budget->scopeKey])
                    ? $byScopeKey[$budget->scopeKey]->intersect($budget)
                    : $budget;
            }
        }

        ksort($byScopeKey, SORT_STRING);

        return $byScopeKey;
    }

    /**
     * @param  list<PolicyLayer>  $layers
     * @return list<PolicyLayer>
     */
    private function normalizeLayers(array $layers): array
    {
        $hasPackageDefaults = false;

        foreach ($layers as $layer) {
            if ($layer->kind === PolicyLayerKind::PackageDefaults) {
                $hasPackageDefaults = true;
                break;
            }
        }

        if (! $hasPackageDefaults) {
            array_unshift(
                $layers,
                new PolicyLayer(
                    kind: PolicyLayerKind::PackageDefaults,
                    scopeKey: 'package_defaults',
                    patch: new SettingPatch(1, []),
                    revision: 1,
                ),
            );
        }

        $indexed = [];

        foreach ($layers as $index => $layer) {
            $indexed[] = [
                'order' => $layer->kind->precedence(),
                'index' => $index,
                'layer' => $layer,
            ];
        }

        usort(
            $indexed,
            static fn (array $a, array $b): int => $a['order'] <=> $b['order'] ?: $a['index'] <=> $b['index'],
        );

        return array_map(static fn (array $item): PolicyLayer => $item['layer'], $indexed);
    }

    private function isLayerStrictlyAboveScope(
        PolicyLayer $layer,
        PolicyScope $targetScope,
        string $targetScopeId,
    ): bool {
        if ($layer->kind->isPostBindingNarrowing()) {
            return false;
        }

        $scopeRank = static fn (PolicyScope $scope): int => match ($scope) {
            PolicyScope::Global => 1,
            PolicyScope::Tenant => 2,
            PolicyScope::Panel => 3,
            PolicyScope::Entity => 4,
        };

        $layerScope = $layer->kind->policyScope() ?? PolicyScope::Global;
        $layerRank = $scopeRank($layerScope);
        $targetRank = $scopeRank($targetScope);

        if ($layerRank < $targetRank) {
            return true;
        }

        if (
            $layerRank === $targetRank
            && $layer->kind === PolicyLayerKind::OrderedEntityAncestry
            && $this->extractScopeId($layer->scopeKey, PolicyScope::Entity) !== $targetScopeId
        ) {
            return true;
        }

        return false;
    }

    private function extractScopeId(string $scopeKey, PolicyScope $scope): string
    {
        $prefix = $scope->value.':';

        return str_starts_with($scopeKey, $prefix)
            ? substr($scopeKey, strlen($prefix))
            : $scopeKey;
    }

    /**
     * @param  list<PolicyLayer>  $orderedLayers
     */
    private function computeEffectiveRevision(array $orderedLayers): int
    {
        $total = 0;

        foreach ($orderedLayers as $layer) {
            if (! $layer->kind->isPostBindingNarrowing() && $layer->kind !== PolicyLayerKind::PackageDefaults) {
                $total += max(1, $layer->revision);
            }
        }

        return max(1, $total);
    }

    /**
     * @param  array<string, mixed>  $effectiveValues
     * @param  list<string>  $disabledSettings
     * @param  array<string, BudgetConstraint>  $budgets
     * @param  array<string, int>  $layerRevisions
     */
    private function computePolicyHash(
        SubjectChain $subjects,
        Uuid $targetId,
        string $trafficKey,
        string $destinationPolicyKey,
        ?string $selectedProfileKey,
        ?int $selectedProfileRevision,
        string $strategyKey,
        string $outcomePolicyKey,
        array $effectiveValues,
        array $disabledSettings,
        array $budgets,
        array $layerRevisions,
    ): ContentHash {
        ksort($effectiveValues, SORT_STRING);
        ksort($layerRevisions, SORT_STRING);

        $subjectKeys = [];

        foreach ($subjects->ancestors() as $ancestor) {
            $subjectKeys[] = $ancestor->key();
        }

        $subjectKeys[] = $subjects->leaf->key();

        $budgetData = [];

        foreach ($budgets as $scopeKey => $budget) {
            $budgetData[$scopeKey] = [
                'emission_interval_us' => $budget->emissionIntervalUs,
                'burst' => $budget->burst,
                'max_inflight' => $budget->maxInflight,
                'mandatory' => $budget->mandatory,
            ];
        }

        return ContentHash::of(
            json_encode([
                'tenant' => $subjects->tenant->value,
                'subjects' => $subjectKeys,
                'target_id' => $targetId->value,
                'traffic_key' => $trafficKey,
                'destination_policy_key' => $destinationPolicyKey,
                'route_profile_key' => $selectedProfileKey,
                'route_profile_revision' => $selectedProfileRevision,
                'strategy_key' => $strategyKey,
                'outcome_policy_key' => $outcomePolicyKey,
                'values' => $effectiveValues,
                'disabled' => $disabledSettings,
                'budgets' => $budgetData,
                'layer_revisions' => $layerRevisions,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * @param  list<PolicyLayer>  $baseLayers
     * @return list<PolicyLayer>
     */
    private function injectPreviewLayer(
        array $baseLayers,
        PolicyScope $scope,
        string $scopeId,
        SettingPatch $patch,
        SubjectChain $subjects,
        SettingRegistry $registry,
    ): array {
        $targetKind = match ($scope) {
            PolicyScope::Global => PolicyLayerKind::GlobalDb,
            PolicyScope::Tenant => PolicyLayerKind::TenantDb,
            PolicyScope::Panel => PolicyLayerKind::PanelDb,
            PolicyScope::Entity => $this->resolveEntityLayerKind($scopeId, $subjects),
        };

        $canonicalScopeKey = str_starts_with($scopeId, $scope->value.':')
            ? $scopeId
            : $scope->value.':'.$scopeId;

        $replaced = false;
        $result = [];

        foreach ($baseLayers as $layer) {
            $matchesLayer = $layer->kind === $targetKind
                && ($layer->scopeKey === $canonicalScopeKey || $layer->scopeKey === $scopeId || $scope !== PolicyScope::Entity);

            if ($matchesLayer) {
                $mergedPatch = $this->mergeStoredAndPreviewPatch($layer->patch, $patch, $scope, $registry);
                $result[] = new PolicyLayer(
                    kind: $targetKind,
                    scopeKey: $canonicalScopeKey,
                    patch: $mergedPatch,
                    revision: $layer->revision + 1,
                    profileKey: $layer->profileKey,
                    profileId: $layer->profileId,
                    profileRevision: $layer->profileRevision,
                    allowedProfileKeys: $layer->allowedProfileKeys,
                    outcomePolicyKey: $layer->outcomePolicyKey,
                    budgets: $layer->budgets,
                    subject: $layer->subject,
                );
                $replaced = true;

                continue;
            }

            $result[] = $layer;
        }

        if (! $replaced) {
            $cleanChanges = [];

            foreach ($patch->changes as $k => $change) {
                if ($change->operation !== PatchOperation::Inherit) {
                    $cleanChanges[$k] = $change;
                }
            }

            $result[] = new PolicyLayer(
                kind: $targetKind,
                scopeKey: $canonicalScopeKey,
                patch: new SettingPatch(1, $cleanChanges),
                revision: 1,
            );
        }

        return $result;
    }

    private function mergeStoredAndPreviewPatch(
        SettingPatch $current,
        SettingPatch $previewPatch,
        PolicyScope $scope,
        SettingRegistry $registry,
    ): SettingPatch {
        $storedChanges = [];

        foreach ($current->changes as $key => $change) {
            if ($change->operation !== PatchOperation::Inherit) {
                $storedChanges[$key] = $change;
            }
        }

        return $registry->apply(new SettingPatch(1, $storedChanges), $previewPatch, $scope);
    }

    private function resolveEntityLayerKind(string $scopeId, SubjectChain $subjects): PolicyLayerKind
    {
        $leafKey = $subjects->leaf->type.':'.$subjects->leaf->id;

        if (
            $scopeId === $leafKey
            || $scopeId === 'entity:'.$leafKey
            || $scopeId === $subjects->leaf->id
            || $scopeId === $subjects->leaf->key()
        ) {
            return PolicyLayerKind::EntityBinding;
        }

        return PolicyLayerKind::OrderedEntityAncestry;
    }

    /**
     * Загружает слои политики и метаданные каталога из PostgreSQL для текущего арендатора.
     *
     * @return array{
     *     layers: list<PolicyLayer>,
     *     target_revision: int,
     *     destination_policy_key: string,
     *     policy_versions: array<string, int>,
     *     profile_direct_member_map: array<string, bool>,
     *     profile_id_map: array<string, Uuid>,
     *     profile_revision_map: array<string, int>,
     *     default_editable_scope: PolicyScope,
     *     default_editable_scope_id: string,
     *     editable_head_id: ?Uuid
     * }
     */
    private function loadDatabaseLayers(
        TenantKey $tenant,
        SubjectChain $subjects,
        Uuid $targetId,
        string $trafficKey,
        string $panelKey,
    ): array {
        $layers = [
            new PolicyLayer(
                kind: PolicyLayerKind::PackageDefaults,
                scopeKey: 'package_defaults',
                patch: new SettingPatch(1, []),
                revision: 1,
            ),
        ];

        $projectDefaults = $this->resolvedProjectDefaults();

        if ($projectDefaults !== []) {
            $layers[] = new PolicyLayer(
                kind: PolicyLayerKind::ProjectDefaults,
                scopeKey: 'project_defaults',
                patch: $this->patchFromConfigArray($projectDefaults),
                revision: 1,
            );
        }

        $panelDefaults = $this->resolvedPanelDefaults($panelKey);

        if ($this->db === null) {
            if ($panelDefaults !== []) {
                $layers[] = new PolicyLayer(
                    kind: PolicyLayerKind::PanelDefinitionDefaults,
                    scopeKey: 'panel_defaults:'.$panelKey,
                    patch: $this->patchFromConfigArray($panelDefaults),
                    revision: 1,
                );
            }

            return [
                'layers' => $layers,
                'target_revision' => 1,
                'destination_policy_key' => 'default',
                'policy_versions' => [],
                'profile_direct_member_map' => [],
                'profile_id_map' => [],
                'profile_revision_map' => [],
                'default_editable_scope' => PolicyScope::Entity,
                'default_editable_scope_id' => $subjects->leaf->type.':'.$subjects->leaf->id,
                'editable_head_id' => null,
            ];
        }

        $db = $this->db;
        $tables = PackageSchema::tables();
        $tenantStr = $tenant->value;

        $targetRow = $db->table($tables['targets'])
            ->where('tenant_key', $tenantStr)
            ->where('id', $targetId->value)
            ->first();

        $targetRevision = $targetRow !== null ? (int) $targetRow->revision : 1;
        $destinationPolicyKey = $targetRow !== null && is_string($targetRow->destination_policy_key)
            ? $targetRow->destination_policy_key
            : 'default';

        // Карта профилей маршрутов арендатора и наличия в них явного direct-маршрута.
        $profileRows = $db->table($tables['route_profiles'])
            ->where('tenant_key', $tenantStr)
            ->whereNull('retired_at')
            ->get();

        $profileIdMap = [];
        $profileKeyById = [];
        $profileRevisionMap = [];
        $profileDirectMemberMap = [];

        foreach ($profileRows as $row) {
            $pKey = (string) $row->profile_key;
            $idStr = (string) $row->id;
            $profileIdMap[$pKey] = new Uuid($idStr);
            $profileKeyById[$idStr] = $pKey;
            $profileRevisionMap[$pKey] = (int) $row->revision;
            $profileDirectMemberMap[$pKey] = false;
        }

        if ($profileKeyById !== []) {
            $directMemberRows = $db->table($tables['route_profile_members'].' as m')
                ->join($tables['routes'].' as r', function ($join): void {
                    $join->on('r.tenant_key', '=', 'm.tenant_key')
                        ->on('r.id', '=', 'm.route_id');
                })
                ->where('m.tenant_key', $tenantStr)
                ->where('r.enabled', true)
                ->whereNull('r.retired_at')
                ->where('r.kind', RouteKind::Direct->value)
                ->select(['m.profile_id'])
                ->get();

            foreach ($directMemberRows as $memberRow) {
                $profKey = $profileKeyById[(string) $memberRow->profile_id] ?? null;

                if ($profKey !== null) {
                    $profileDirectMemberMap[$profKey] = true;
                }
            }
        }

        // Активные бюджеты арендатора (и системного глобального арендатора).
        $budgetRows = $db->table($tables['budgets'])
            ->whereIn('tenant_key', array_values(array_unique([$tenantStr, TenantKey::SYSTEM])))
            ->orderBy('scope_key')
            ->get();

        /** @var array<string, list<BudgetConstraint>> $budgetsByLayerBucket */
        $budgetsByLayerBucket = [
            'global' => [],
            'tenant' => [],
            'panel' => [],
            'entity' => [],
        ];

        $policyVersions = [];

        foreach ($budgetRows as $bRow) {
            $bScopeKey = (string) $bRow->scope_key;
            $constraint = new BudgetConstraint(
                scopeKey: $bScopeKey,
                emissionIntervalUs: (int) $bRow->emission_interval_us,
                burst: (int) $bRow->burst,
                maxInflight: (int) $bRow->max_inflight,
                mandatory: (bool) $bRow->mandatory,
            );
            $policyVersions['budget:'.(string) $bRow->tenant_key.':'.$bScopeKey] = (int) $bRow->revision;

            if ((string) $bRow->tenant_key === TenantKey::SYSTEM || str_starts_with($bScopeKey, 'global:')) {
                $budgetsByLayerBucket['global'][] = $constraint;
            } elseif (str_starts_with($bScopeKey, 'tenant:')) {
                $budgetsByLayerBucket['tenant'][] = $constraint;
            } elseif (str_starts_with($bScopeKey, 'panel:')) {
                $budgetsByLayerBucket['panel'][] = $constraint;
            } else {
                $budgetsByLayerBucket['entity'][] = $constraint;
            }
        }

        foreach ($profileRevisionMap as $pKey => $pRev) {
            $policyVersions['profile:'.$pKey] = $pRev;
        }

        // Загружаем policy_heads и текущие policy_revisions для tenant и system.
        $headRows = $db->table($tables['policy_heads'].' as h')
            ->join($tables['policy_revisions'].' as r', function ($join): void {
                $join->on('r.tenant_key', '=', 'h.tenant_key')
                    ->on('r.head_id', '=', 'h.id')
                    ->on('r.id', '=', 'h.current_revision_id');
            })
            ->whereIn('h.tenant_key', array_values(array_unique([$tenantStr, TenantKey::SYSTEM])))
            ->select([
                'h.id as head_id',
                'h.tenant_key',
                'h.scope_key',
                'h.revision',
                'r.definition',
            ])
            ->orderBy('h.scope_key')
            ->get();

        $headsById = [];
        $headsByScopeKey = [];

        foreach ($headRows as $hRow) {
            $headData = [
                'head_id' => new Uuid((string) $hRow->head_id),
                'tenant_key' => (string) $hRow->tenant_key,
                'scope_key' => (string) $hRow->scope_key,
                'revision' => (int) $hRow->revision,
                'patch' => SettingPatch::decode((string) $hRow->definition),
            ];

            $headsById[(string) $hRow->head_id] = $headData;
            $headsByScopeKey[$headData['tenant_key'].'|'.$headData['scope_key']] = $headData;
            $policyVersions['head:'.$headData['tenant_key'].':'.$headData['scope_key']] = $headData['revision'];
        }

        // Layer 3: global_db
        $globalCandidates = array_values(array_unique([
            TenantKey::SYSTEM.'|global:'.TenantKey::SYSTEM,
            TenantKey::SYSTEM.'|global:'.$trafficKey,
            $tenantStr.'|global:'.TenantKey::SYSTEM,
            $tenantStr.'|global:default',
        ]));

        $globalAdded = false;

        foreach ($globalCandidates as $gCandidate) {
            if (isset($headsByScopeKey[$gCandidate])) {
                $gHead = $headsByScopeKey[$gCandidate];
                $layers[] = new PolicyLayer(
                    kind: PolicyLayerKind::GlobalDb,
                    scopeKey: $gHead['scope_key'],
                    patch: $gHead['patch'],
                    revision: $gHead['revision'],
                    budgets: ! $globalAdded ? $budgetsByLayerBucket['global'] : [],
                );
                $globalAdded = true;
            }
        }

        if (! $globalAdded && $budgetsByLayerBucket['global'] !== []) {
            $layers[] = new PolicyLayer(
                kind: PolicyLayerKind::GlobalDb,
                scopeKey: 'global:'.TenantKey::SYSTEM,
                patch: new SettingPatch(1, []),
                revision: 1,
                budgets: $budgetsByLayerBucket['global'],
            );
        }

        // Layer 4: tenant_db
        $tenantCandidates = array_values(array_unique([
            $tenantStr.'|tenant:'.$tenantStr,
            $tenantStr.'|tenant:'.$tenantStr.':'.$trafficKey,
        ]));

        $tenantAdded = false;
        $editableHeadId = null;

        foreach ($tenantCandidates as $tCandidate) {
            if (isset($headsByScopeKey[$tCandidate])) {
                $tHead = $headsByScopeKey[$tCandidate];
                $editableHeadId = $tHead['head_id'];
                $layers[] = new PolicyLayer(
                    kind: PolicyLayerKind::TenantDb,
                    scopeKey: $tHead['scope_key'],
                    patch: $tHead['patch'],
                    revision: $tHead['revision'],
                    budgets: ! $tenantAdded ? $budgetsByLayerBucket['tenant'] : [],
                );
                $tenantAdded = true;
            }
        }

        if (! $tenantAdded && $budgetsByLayerBucket['tenant'] !== []) {
            $layers[] = new PolicyLayer(
                kind: PolicyLayerKind::TenantDb,
                scopeKey: 'tenant:'.$tenantStr,
                patch: new SettingPatch(1, []),
                revision: 1,
                budgets: $budgetsByLayerBucket['tenant'],
            );
        }

        // Layer 5: panel_definition_defaults
        if ($panelDefaults !== []) {
            $layers[] = new PolicyLayer(
                kind: PolicyLayerKind::PanelDefinitionDefaults,
                scopeKey: 'panel_defaults:'.$panelKey,
                patch: $this->patchFromConfigArray($panelDefaults),
                revision: 1,
            );
        }

        // Layer 6: panel_db
        $panelCandidates = array_values(array_unique([
            $tenantStr.'|panel:'.$panelKey,
            $tenantStr.'|panel:'.$panelKey.':'.$trafficKey,
        ]));

        $panelAdded = false;

        foreach ($panelCandidates as $pCandidate) {
            if (isset($headsByScopeKey[$pCandidate])) {
                $pHead = $headsByScopeKey[$pCandidate];
                $layers[] = new PolicyLayer(
                    kind: PolicyLayerKind::PanelDb,
                    scopeKey: $pHead['scope_key'],
                    patch: $pHead['patch'],
                    revision: $pHead['revision'],
                    budgets: ! $panelAdded ? $budgetsByLayerBucket['panel'] : [],
                );
                $panelAdded = true;
            }
        }

        if (! $panelAdded && $budgetsByLayerBucket['panel'] !== []) {
            $layers[] = new PolicyLayer(
                kind: PolicyLayerKind::PanelDb,
                scopeKey: 'panel:'.$panelKey,
                patch: new SettingPatch(1, []),
                revision: 1,
                budgets: $budgetsByLayerBucket['panel'],
            );
        }

        // Загружаем привязки pxm_bindings для цепочки субъектов.
        $bindingRows = $db->table($tables['bindings'])
            ->where('tenant_key', $tenantStr)
            ->whereNull('retired_at')
            ->orderBy('revision')
            ->get();

        $bindingsBySubjectPair = [];

        foreach ($bindingRows as $bRow) {
            $pairKey = (string) $bRow->subject_type.':'.(string) $bRow->subject_id;
            $bindingsBySubjectPair[$pairKey][] = $bRow;
            $policyVersions['binding:'.(string) $bRow->id] = (int) $bRow->revision;
        }

        // Layer 7 (ordered_entity_ancestry) и Layer 8 (entity_binding)
        $orderedSubjects = [...$subjects->ancestors(), $subjects->leaf];
        $lastSubjectIndex = count($orderedSubjects) - 1;
        $defaultEditableScope = PolicyScope::Entity;
        $defaultEditableScopeId = $subjects->leaf->type.':'.$subjects->leaf->id;
        $remainingEntityBudgets = $budgetsByLayerBucket['entity'];

        foreach ($orderedSubjects as $index => $subject) {
            $isLeaf = $index === $lastSubjectIndex;
            $layerKind = $isLeaf
                ? PolicyLayerKind::EntityBinding
                : PolicyLayerKind::OrderedEntityAncestry;

            $pairKey = $subject->type.':'.$subject->id;
            $entityScopeCandidates = array_values(array_unique([
                $tenantStr.'|entity:'.$pairKey,
                $tenantStr.'|entity:'.$subject->id,
                $tenantStr.'|entity:'.$pairKey.':'.$trafficKey,
            ]));

            $subjectBudgets = [];
            $unmatchedBudgets = [];

            foreach ($remainingEntityBudgets as $bConstraint) {
                if (
                    str_contains($bConstraint->scopeKey, $pairKey)
                    || str_ends_with($bConstraint->scopeKey, ':'.$subject->id)
                    || $isLeaf
                ) {
                    $subjectBudgets[] = $bConstraint;
                } else {
                    $unmatchedBudgets[] = $bConstraint;
                }
            }

            $remainingEntityBudgets = $unmatchedBudgets;
            $addedHeadIds = [];

            foreach ($entityScopeCandidates as $eCandidate) {
                if (isset($headsByScopeKey[$eCandidate])) {
                    $eHead = $headsByScopeKey[$eCandidate];
                    $addedHeadIds[$eHead['head_id']->value] = true;

                    if ($isLeaf) {
                        $editableHeadId = $eHead['head_id'];
                        $defaultEditableScopeId = $this->extractScopeId($eHead['scope_key'], PolicyScope::Entity);
                    }

                    $layers[] = new PolicyLayer(
                        kind: $layerKind,
                        scopeKey: $eHead['scope_key'],
                        patch: $eHead['patch'],
                        revision: $eHead['revision'],
                        budgets: $subjectBudgets,
                        subject: $subject,
                    );
                    $subjectBudgets = [];
                }
            }

            foreach ($bindingsBySubjectPair[$pairKey] ?? [] as $bRow) {
                $boundHead = $headsById[(string) $bRow->head_id] ?? null;

                if ($isLeaf && $boundHead !== null) {
                    $editableHeadId = $boundHead['head_id'];
                }

                $boundProfileKey = $bRow->profile_id !== null
                    ? ($profileKeyById[(string) $bRow->profile_id] ?? null)
                    : null;

                $boundProfileId = $bRow->profile_id !== null
                    ? new Uuid((string) $bRow->profile_id)
                    : null;

                $boundProfileRevision = $boundProfileKey !== null
                    ? ($profileRevisionMap[$boundProfileKey] ?? 1)
                    : null;

                $patchForBinding = $boundHead !== null && ! isset($addedHeadIds[$boundHead['head_id']->value])
                    ? $boundHead['patch']
                    : new SettingPatch(1, []);

                $revisionForBinding = $boundHead !== null
                    ? max((int) $bRow->revision, $boundHead['revision'])
                    : (int) $bRow->revision;

                $layers[] = new PolicyLayer(
                    kind: $layerKind,
                    scopeKey: 'entity:'.$pairKey,
                    patch: $patchForBinding,
                    revision: $revisionForBinding,
                    profileKey: $boundProfileKey,
                    profileId: $boundProfileId,
                    profileRevision: $boundProfileRevision,
                    budgets: $subjectBudgets,
                    subject: $subject,
                );
                $subjectBudgets = [];
            }

            if ($subjectBudgets !== []) {
                $layers[] = new PolicyLayer(
                    kind: $layerKind,
                    scopeKey: 'entity:'.$pairKey,
                    patch: new SettingPatch(1, []),
                    revision: 1,
                    budgets: $subjectBudgets,
                    subject: $subject,
                );
            }
        }

        return [
            'layers' => $layers,
            'target_revision' => $targetRevision,
            'destination_policy_key' => $destinationPolicyKey,
            'policy_versions' => $policyVersions,
            'profile_direct_member_map' => $profileDirectMemberMap,
            'profile_id_map' => $profileIdMap,
            'profile_revision_map' => $profileRevisionMap,
            'default_editable_scope' => $defaultEditableScope,
            'default_editable_scope_id' => $defaultEditableScopeId,
            'editable_head_id' => $editableHeadId,
        ];
    }

    /**
     * Пересчитывает только флаги editable/reason для закэшированной EffectivePolicy
     * с учётом прав текущего актора (включая смену актора в долгоживущем воркере).
     */
    private function reattachLivePermissionExplanations(
        EffectivePolicy $policy,
        TenantKey $tenant,
        PolicyScope $editableScope,
        string $editableScopeId,
        ?Uuid $editableHeadId,
    ): EffectivePolicy {
        if ($this->access === null) {
            return $policy;
        }

        $registry = $this->currentRegistry();
        $updatedExplanations = [];
        $changed = false;

        foreach ($policy->explanations as $key => $field) {
            $descriptor = $registry->descriptor($key);
            $scopeAllowed = in_array($editableScope, $descriptor->allowedScopes, true);
            $hasPermission = $scopeAllowed && $this->canEditField(
                tenant: $tenant,
                descriptor: $descriptor,
                scope: $editableScope,
                scopeId: $editableScopeId,
                headId: $editableHeadId,
            );

            $lockedByUpper = ! $field->editable && $field->reason === 'locked_by_upper_bound';
            $newEditable = ! $field->sensitive && $hasPermission && ! $lockedByUpper;

            $newReason = $field->reason;

            if ($field->sensitive) {
                $newReason = 'redacted_sensitive_field';
            } elseif (! $scopeAllowed) {
                $newReason = 'scope_not_allowed';
            } elseif (! $hasPermission) {
                $newReason = 'locked_by_permission';
            } elseif ($field->reason === 'locked_by_permission') {
                $newReason = $field->clamped
                    ? 'clamped_by_upper_bound'
                    : ($field->inherited ? 'package_default' : 'explicit_override');
            }

            if ($newEditable !== $field->editable || $newReason !== $field->reason) {
                $changed = true;
                $updatedExplanations[$key] = new FieldExplanation(
                    key: $field->key,
                    effectiveValue: $field->effectiveValue,
                    sourceScope: $field->sourceScope,
                    sourceLayer: $field->sourceLayer,
                    sourceRevision: $field->sourceRevision,
                    inherited: $field->inherited,
                    explicit: $field->explicit,
                    disabled: $field->disabled,
                    clamped: $field->clamped,
                    editable: $newEditable,
                    permission: $field->permission,
                    reason: $newReason,
                    minimumBound: $field->minimumBound,
                    maximumBound: $field->maximumBound,
                    clampedByScope: $field->clampedByScope,
                    clampedByRevision: $field->clampedByRevision,
                    sensitive: $field->sensitive,
                    provenance: $field->provenance,
                );
            } else {
                $updatedExplanations[$key] = $field;
            }
        }

        if (! $changed) {
            return $policy;
        }

        return new EffectivePolicy(
            tenantKey: $policy->tenantKey,
            subjectChain: $policy->subjectChain,
            targetId: $policy->targetId,
            destinationPolicyKey: $policy->destinationPolicyKey,
            trafficKey: $policy->trafficKey,
            routeProfileId: $policy->routeProfileId,
            routeProfileKey: $policy->routeProfileKey,
            routeProfileRevision: $policy->routeProfileRevision,
            allowedProfileKeys: $policy->allowedProfileKeys,
            timeouts: $policy->timeouts,
            retryDelaysDisabled: $policy->retryDelaysDisabled,
            allowDirectRoute: $policy->allowDirectRoute,
            strategyKey: $policy->strategyKey,
            outcomePolicyKey: $policy->outcomePolicyKey,
            deniedRoutes: $policy->deniedRoutes,
            allowedDestinations: $policy->allowedDestinations,
            budgets: $policy->budgets,
            values: $policy->values,
            disabledSettings: $policy->disabledSettings,
            layerRevisions: $policy->layerRevisions,
            appliedLayers: $policy->appliedLayers,
            explanations: $updatedExplanations,
            effectiveRevision: $policy->effectiveRevision,
            policyHash: $policy->policyHash,
            schemaVersion: $policy->schemaVersion,
        );
    }

    /**
     * @return array{
     *     profile: RouteProfile,
     *     eligible_routes: list<Route>,
     *     health_epochs: HealthEpochMap,
     *     blocked_direct_only: bool
     * }
     */
    private function inspectLiveProfileRoutes(
        TenantKey $tenant,
        object $profileRow,
        Target $target,
        EffectivePolicy $intersectedPolicy,
    ): array {
        $db = $this->requireConnection();
        $tables = PackageSchema::tables();
        $profileId = new Uuid((string) $profileRow->id);

        $memberRows = $db->table($tables['route_profile_members'])
            ->where('tenant_key', $tenant->value)
            ->where('profile_id', $profileId->value)
            ->orderBy('position')
            ->get();

        $allMemberRouteIds = [];
        $eligibleRoutes = [];
        $epochs = [];
        $sawDirectRouteDeniedByPolicy = false;
        $now = new DateTimeImmutable;

        $profileHealthRow = $db->table($tables['profile_health'])
            ->where('tenant_key', $tenant->value)
            ->where('profile_id', $profileId->value)
            ->first();

        if ($profileHealthRow !== null) {
            $epochs['profile:'.$profileId->value] = max(1, (int) $profileHealthRow->health_epoch);
        }

        foreach ($memberRows as $mRow) {
            $routeId = new Uuid((string) $mRow->route_id);
            $allMemberRouteIds[] = $routeId->value;

            $route = $this->loadLiveRoute($db, $tenant, $routeId);

            if ($route === null || ! $route->enabled || $route->isRetired()) {
                continue;
            }

            if (
                $route->tenantKey->value !== (string) $mRow->route_owner_tenant_key
                || $route->accessGrant?->id->value !== $mRow->shared_grant_id
            ) {
                continue;
            }

            if ($intersectedPolicy->isRouteDenied($route)) {
                continue;
            }

            if ($route->kind === RouteKind::Direct && ! $intersectedPolicy->allowDirectRoute) {
                $sawDirectRouteDeniedByPolicy = true;

                continue;
            }

            // Проверяем материализованное здоровье маршрута и пары (route, target).
            $routeHealthRow = $db->table($tables['route_health'])
                ->where('tenant_key', $tenant->value)
                ->where('route_id', $routeId->value)
                ->first();

            $routeTargetHealthRow = $db->table($tables['route_target_health'])
                ->where('tenant_key', $tenant->value)
                ->where('route_id', $routeId->value)
                ->where('target_id', $target->id->value)
                ->first();

            $routeEpoch = $routeHealthRow !== null ? max(1, (int) $routeHealthRow->health_epoch) : 1;
            $epochs['route:'.$routeId->value] = $routeEpoch;

            if ($routeTargetHealthRow !== null) {
                $epochs['route_target:'.$routeId->value.':'.$target->id->value] = max(1, (int) $routeTargetHealthRow->health_epoch);
            }

            if ($this->isHealthRowCircuitOpen($routeHealthRow, $now) || $this->isHealthRowCircuitOpen($routeTargetHealthRow, $now)) {
                continue;
            }

            $eligibleRoutes[] = $route;
        }

        if ($epochs === []) {
            $epochs['profile:'.$profileId->value] = 1;
        }

        $profileRow->member_route_ids = $allMemberRouteIds;
        $profile = CatalogRowMapper::profile($profileRow);

        return [
            'profile' => $profile,
            'eligible_routes' => $eligibleRoutes,
            'health_epochs' => new HealthEpochMap($epochs),
            'blocked_direct_only' => $eligibleRoutes === [] && $sawDirectRouteDeniedByPolicy,
        ];
    }

    /**
     * @param  array{
     *     profile: RouteProfile,
     *     eligible_routes: list<Route>,
     *     health_epochs: HealthEpochMap,
     *     blocked_direct_only: bool
     * }  $liveProfileState
     */
    private function verifySelectedRouteLive(
        TenantKey $tenant,
        Route $selectedRoute,
        PinnedPolicySnapshot $pinned,
        EffectivePolicy $intersectedPolicy,
        array $liveProfileState,
    ): NeedsReplan|ExecutionBlocked|null {
        $db = $this->requireConnection();
        $tables = PackageSchema::tables();

        $hasAlternateEligibleRoute = false;

        foreach ($liveProfileState['eligible_routes'] as $candidate) {
            if (! $candidate->id->equals($selectedRoute->id)) {
                $hasAlternateEligibleRoute = true;
                break;
            }
        }

        if ($intersectedPolicy->isRouteDenied($selectedRoute)) {
            if ($hasAlternateEligibleRoute) {
                return new NeedsReplan(
                    pinned: $pinned,
                    livePolicy: $intersectedPolicy,
                    reason: 'selected_route_denied',
                    pinnedProfileKey: $pinned->routeProfileKey,
                    liveProfileKey: $intersectedPolicy->routeProfileKey,
                    affectedRoutes: [$selectedRoute->routeKey],
                );
            }

            return new ExecutionBlocked(
                reason: BlockedReason::EmptyProfile,
                safeDetails: [
                    'route_key' => $selectedRoute->routeKey,
                    'status' => 'selected_route_denied_by_live_policy',
                ],
            );
        }

        if ($selectedRoute->kind === RouteKind::Direct && ! $intersectedPolicy->allowDirectRoute) {
            if ($hasAlternateEligibleRoute) {
                return new NeedsReplan(
                    pinned: $pinned,
                    livePolicy: $intersectedPolicy,
                    reason: 'direct_route_disallowed',
                    pinnedProfileKey: $pinned->routeProfileKey,
                    liveProfileKey: $intersectedPolicy->routeProfileKey,
                    affectedRoutes: [$selectedRoute->routeKey],
                );
            }

            return new ExecutionBlocked(
                reason: BlockedReason::DirectRouteNotAllowed,
                safeDetails: ['route_key' => $selectedRoute->routeKey],
            );
        }

        if ($selectedRoute->accessGrant !== null) {
            $grantRow = $db->table($tables['shared_route_grants'])
                ->where('tenant_key', $selectedRoute->tenantKey->value)
                ->where('consumer_tenant_key', $tenant->value)
                ->where('id', $selectedRoute->accessGrant->id->value)
                ->first();

            if ($grantRow === null || $grantRow->retired_at !== null || (int) $grantRow->revision !== $selectedRoute->accessGrant->revision) {
                if ($hasAlternateEligibleRoute) {
                    return new NeedsReplan(
                        pinned: $pinned,
                        livePolicy: $intersectedPolicy,
                        reason: 'shared_grant_revoked_or_changed',
                        pinnedProfileKey: $pinned->routeProfileKey,
                        liveProfileKey: $intersectedPolicy->routeProfileKey,
                        affectedRoutes: [$selectedRoute->routeKey],
                    );
                }

                return new ExecutionBlocked(
                    reason: BlockedReason::EmptyProfile,
                    safeDetails: [
                        'route_key' => $selectedRoute->routeKey,
                        'status' => 'shared_grant_revoked',
                    ],
                );
            }
        }

        if ($selectedRoute->endpointId !== null) {
            $endpointRow = $db->table($tables['endpoints'])
                ->where('tenant_key', $selectedRoute->tenantKey->value)
                ->where('id', $selectedRoute->endpointId->value)
                ->first();

            if ($endpointRow === null || $endpointRow->retired_at !== null) {
                if ($hasAlternateEligibleRoute) {
                    return new NeedsReplan(
                        pinned: $pinned,
                        livePolicy: $intersectedPolicy,
                        reason: 'endpoint_retired',
                        pinnedProfileKey: $pinned->routeProfileKey,
                        liveProfileKey: $intersectedPolicy->routeProfileKey,
                        affectedRoutes: [$selectedRoute->routeKey],
                    );
                }

                return new ExecutionBlocked(
                    reason: BlockedReason::EmptyProfile,
                    safeDetails: [
                        'route_key' => $selectedRoute->routeKey,
                        'status' => 'endpoint_retired',
                    ],
                );
            }

            $liveCredentialVersion = (int) ($endpointRow->credential_version ?? 1);

            if ($liveCredentialVersion !== $selectedRoute->credentialVersion) {
                return new NeedsReplan(
                    pinned: $pinned,
                    livePolicy: $intersectedPolicy,
                    reason: 'credential_rotated',
                    pinnedProfileKey: $pinned->routeProfileKey,
                    liveProfileKey: $intersectedPolicy->routeProfileKey,
                    affectedRoutes: [$selectedRoute->routeKey],
                );
            }
        }

        $liveRoute = $this->loadLiveRoute($db, $tenant, $selectedRoute->id);

        if ($liveRoute === null || ! $liveRoute->enabled || $liveRoute->isRetired()) {
            if ($hasAlternateEligibleRoute) {
                return new NeedsReplan(
                    pinned: $pinned,
                    livePolicy: $intersectedPolicy,
                    reason: 'route_revoked',
                    pinnedProfileKey: $pinned->routeProfileKey,
                    liveProfileKey: $intersectedPolicy->routeProfileKey,
                    affectedRoutes: [$selectedRoute->routeKey],
                );
            }

            return new ExecutionBlocked(
                reason: BlockedReason::EmptyProfile,
                safeDetails: [
                    'route_key' => $selectedRoute->routeKey,
                    'status' => 'route_revoked',
                ],
            );
        }

        $SelectedStillEligible = false;

        foreach ($liveProfileState['eligible_routes'] as $candidate) {
            if ($candidate->id->equals($selectedRoute->id)) {
                $SelectedStillEligible = true;
                break;
            }
        }

        if (! $SelectedStillEligible) {
            if ($hasAlternateEligibleRoute) {
                return new NeedsReplan(
                    pinned: $pinned,
                    livePolicy: $intersectedPolicy,
                    reason: 'selected_route_unhealthy',
                    pinnedProfileKey: $pinned->routeProfileKey,
                    liveProfileKey: $intersectedPolicy->routeProfileKey,
                    affectedRoutes: [$selectedRoute->routeKey],
                );
            }

            return new ExecutionBlocked(
                reason: BlockedReason::EmptyProfile,
                safeDetails: [
                    'route_key' => $selectedRoute->routeKey,
                    'status' => 'selected_route_unhealthy',
                ],
            );
        }

        return null;
    }

    private function loadLiveRoute(Connection $db, TenantKey $tenant, Uuid $routeId): ?Route
    {
        $tables = PackageSchema::tables();
        $grant = null;

        $row = $db->table($tables['routes'])
            ->where('tenant_key', $tenant->value)
            ->where('id', $routeId->value)
            ->first();

        if ($row === null) {
            $grant = (new SharedRouteStorage($db))->forConsumer($tenant, $routeId);

            if ($grant !== null) {
                $row = $db->table($tables['routes'])
                    ->where('tenant_key', $grant->owner->value)
                    ->where('id', $routeId->value)
                    ->first();
            }
        }

        if ($row === null || ($grant !== null && $row->retired_at !== null)) {
            return null;
        }

        $endpoint = null;

        if ($row->endpoint_id !== null) {
            $endpoint = $db->table($tables['endpoints'])
                ->where('tenant_key', (string) $row->tenant_key)
                ->where('id', (string) $row->endpoint_id)
                ->first();

            if ($endpoint === null || $endpoint->retired_at !== null) {
                return null;
            }
        }

        $row->credential_version = $endpoint->credential_version ?? 1;
        $row->endpoint_revision = $endpoint === null ? null : (int) $endpoint->revision;

        return CatalogRowMapper::route($row, $grant);
    }

    private function isHealthRowCircuitOpen(?object $healthRow, DateTimeImmutable $now): bool
    {
        if ($healthRow === null) {
            return false;
        }

        if ((string) $healthRow->state === 'open') {
            if ($healthRow->open_until === null) {
                return true;
            }

            return new DateTimeImmutable((string) $healthRow->open_until) > $now;
        }

        return false;
    }

    /**
     * @param  list<string>  $budgetScopeKeys
     */
    private function hasExhaustedLiveQuotaWindow(Connection $db, TenantKey $tenant, array $budgetScopeKeys): ?string
    {
        if ($budgetScopeKeys === []) {
            return null;
        }

        $tables = PackageSchema::tables();
        $exhaustedRow = $db->table($tables['quota_windows'].' as q')
            ->join($tables['budgets'].' as b', function ($join): void {
                $join->on('b.tenant_key', '=', 'q.tenant_key')
                    ->on('b.id', '=', 'q.budget_id');
            })
            ->whereIn('b.tenant_key', array_values(array_unique([$tenant->value, TenantKey::SYSTEM])))
            ->whereIn('b.scope_key', $budgetScopeKeys)
            ->whereRaw('q.starts_at <= clock_timestamp() AND q.ends_at > clock_timestamp()')
            ->whereRaw('q.reserved + q.spent >= q.quota_limit')
            ->select(['b.scope_key'])
            ->first();

        return $exhaustedRow !== null ? (string) $exhaustedRow->scope_key : null;
    }

    /**
     * @return array{fields: list<string>, layers: list<string>}
     */
    private function detectPolicyDrift(PinnedPolicySnapshot $pinned, EffectivePolicy $live): array
    {
        $fields = [];

        if ($pinned->timeouts->connectTimeoutMs !== $live->timeouts->connectTimeoutMs) {
            $fields[] = 'timeout.connect_ms';
        }

        if ($pinned->timeouts->readTimeoutMs !== $live->timeouts->readTimeoutMs) {
            $fields[] = 'timeout.read_ms';
        }

        if ($pinned->timeouts->totalTimeoutMs !== $live->timeouts->totalTimeoutMs) {
            $fields[] = 'timeout.total_ms';
        }

        if ($pinned->timeouts->maxAttempts !== $live->timeouts->maxAttempts) {
            $fields[] = 'retry.max_attempts';
        }

        if (
            $pinned->retryDelaysDisabled !== $live->retryDelaysDisabled
            || $pinned->timeouts->retryDelaysMs !== $live->timeouts->retryDelaysMs
        ) {
            $fields[] = 'retry.delays_ms';
        }

        if ($pinned->timeouts->maxRedirects !== $live->timeouts->maxRedirects) {
            $fields[] = 'http.max_redirects';
        }

        if ($pinned->allowDirectRoute !== $live->allowDirectRoute) {
            $fields[] = 'route.allow_direct';
        }

        if ($pinned->deniedRoutes !== $live->deniedRoutes) {
            $fields[] = 'routes.denied';
        }

        if ($pinned->allowedDestinations !== $live->allowedDestinations) {
            $fields[] = 'destinations.allowed';
        }

        if ($pinned->strategyKey !== $live->strategyKey) {
            $fields[] = 'route.strategy';
        }

        $layers = [];
        $allLayerKeys = array_values(array_unique([
            ...array_keys($pinned->layerRevisions),
            ...array_keys($live->layerRevisions),
        ]));

        foreach ($allLayerKeys as $layerKey) {
            if (($pinned->layerRevisions[$layerKey] ?? null) !== ($live->layerRevisions[$layerKey] ?? null)) {
                $layers[] = $layerKey;
            }
        }

        if ($fields === [] && $layers === []) {
            $layers[] = 'effective_revision';
        }

        return [
            'fields' => $fields,
            'layers' => $layers,
        ];
    }

    private function canEditField(
        TenantKey $tenant,
        SettingDescriptor $descriptor,
        PolicyScope $scope,
        string $scopeId,
        ?Uuid $headId,
    ): bool {
        if ($this->access === null) {
            return true;
        }

        $scopeResource = $scope->authorizationId($tenant, $scopeId);
        $resourceForSetting = $headId ?? $scopeResource;

        try {
            $this->access->authorize($tenant, 'policy.update', $resourceForSetting);
            $this->access->authorize($tenant, 'policy.scope.'.$scope->value.'.edit', $scopeResource);
            $this->access->authorize($tenant, $descriptor->permission, $resourceForSetting);

            return true;
        } catch (AuthorizationDenied|RuntimeException) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function patchFromConfigArray(array $values): SettingPatch
    {
        $changes = [];

        foreach ($values as $key => $val) {
            if ($val instanceof SettingChange) {
                $changes[$key] = $val;
            } else {
                $changes[$key] = SettingChange::set($val);
            }
        }

        return new SettingPatch(1, $changes);
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
            ? $config['project_defaults']
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

        return isset($config['panel_defaults'][$panelKey]) && is_array($config['panel_defaults'][$panelKey])
            ? $config['panel_defaults'][$panelKey]
            : [];
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
            ? $config['hard_bounds']
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

        return $registryFp.':'.substr($configFp, 0, 16);
    }

    private function requireConnection(): Connection
    {
        if ($this->db === null) {
            throw new RuntimeException('Database connection is required for live policy operations.');
        }

        return $this->db;
    }
}
