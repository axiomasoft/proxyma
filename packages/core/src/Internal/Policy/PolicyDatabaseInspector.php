<?php

declare(strict_types=1);

namespace Proxyma\Internal\Policy;

use Closure;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Packman\Attributes\Internal;
use Proxyma\Application\Admission\TrafficSharePolicy;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Bindings\BindingContext;
use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Application\Panels\PanelSubjects;
use Proxyma\Application\Policy\SettingRegistry;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Catalog\RouteProfile;
use Proxyma\Domain\Catalog\Target;
use Proxyma\Domain\Execution\BlockedReason;
use Proxyma\Domain\Execution\ExecutionBlocked;
use Proxyma\Domain\Execution\SafeUrl;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\HealthEpochMap;
use Proxyma\Domain\Panels\SubjectResolutionFailed;
use Proxyma\Domain\Policy\BudgetConstraint;
use Proxyma\Domain\Policy\EffectivePolicy;
use Proxyma\Domain\Policy\FieldExplanation;
use Proxyma\Domain\Policy\NeedsReplan;
use Proxyma\Domain\Policy\PinnedPolicySnapshot;
use Proxyma\Domain\Policy\PolicyLayer;
use Proxyma\Domain\Policy\PolicyLayerKind;
use Proxyma\Domain\Policy\PolicyScope;
use Proxyma\Domain\Policy\SettingChange;
use Proxyma\Domain\Policy\SettingDescriptor;
use Proxyma\Domain\Policy\SettingPatch;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\CatalogRowMapper;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Security\SharedRouteStorage;
use Proxyma\Internal\Database\RowValues;
use RuntimeException;

/** Database policy layer loader, live route health verifier, and drift inspector. */
#[Internal]
final readonly class PolicyDatabaseInspector
{
    /**
     * @param  Closure(): Connection  $requireConnection
     * @param  Closure(): SettingRegistry  $currentRegistry
     * @param  Closure(): array<string, mixed>  $resolvedProjectDefaults
     * @param  Closure(string): array<string, mixed>  $resolvedPanelDefaults
     */
    public function __construct(
        private ?Connection $db,
        private ?CatalogAccess $access,
        private ?DefinitionRegistry $definitions,
        private Closure $requireConnection,
        private Closure $currentRegistry,
        private Closure $resolvedProjectDefaults,
        private Closure $resolvedPanelDefaults,
    ) {}

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
    public function loadDatabaseLayers(
        TenantKey $tenant,
        SubjectChain $subjects,
        Uuid $targetId,
        string $trafficKey,
        string $panelKey,
        string $panelAction = 'execute',
    ): array {
        $panelSlot = null;
        if ($this->definitions !== null && $this->definitions->hasPanel($panelKey)) {
            if (($this->db?->transactionLevel() ?? 0) !== 0) {
                throw new SubjectResolutionFailed('Resolve panel subjects before opening a SQL transaction.');
            }
            $panelSlot = $this->definitions->panel($panelKey)->slot($trafficKey);
            new PanelSubjects($this->definitions)->verify($panelKey, $subjects);
            if ($this->access === null) {
                throw new AuthorizationDenied;
            }
            if (! in_array($panelAction, ['view', 'execute'], true)) {
                throw new AuthorizationDenied;
            }
            new BindingContext($this->access, $this->definitions)->authorize($subjects->leaf, $panelKey, $trafficKey, $panelAction);
        }
        $layers = [
            new PolicyLayer(
                kind: PolicyLayerKind::PackageDefaults,
                scopeKey: 'package_defaults',
                patch: new SettingPatch(1, []),
                revision: 1,
            ),
        ];

        $projectDefaults = ($this->resolvedProjectDefaults)();

        if ($projectDefaults !== []) {
            $layers[] = new PolicyLayer(
                kind: PolicyLayerKind::ProjectDefaults,
                scopeKey: 'project_defaults',
                patch: $this->patchFromConfigArray($projectDefaults),
                revision: 1,
            );
        }

        $panelDefaults = ($this->resolvedPanelDefaults)($panelKey);
        if ($panelSlot !== null) {
            $panelDefaults = [];
            foreach ($panelSlot->defaults->changes as $key => $change) {
                $panelDefaults[$key] = $change;
            }
        }

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

        $targetRevision = $targetRow !== null ? RowValues::colInt($targetRow, 'revision') : 1;
        $destinationPolicyKey = $targetRow !== null
            ? (RowValues::colNullableStr($targetRow, 'destination_policy_key') ?? 'default')
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
            $pKey = RowValues::colStr($row, 'profile_key');
            $idStr = RowValues::colStr($row, 'id');
            $profileIdMap[$pKey] = new Uuid($idStr);
            $profileKeyById[$idStr] = $pKey;
            $profileRevisionMap[$pKey] = RowValues::colInt($row, 'revision');
            $profileDirectMemberMap[$pKey] = false;
        }

        if ($profileKeyById !== []) {
            $directMemberRows = $db->table($tables['route_profile_members'].' as m')
                ->join($tables['routes'].' as r', static function (JoinClause $join): void {
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
                $profKey = $profileKeyById[RowValues::colStr($memberRow, 'profile_id')] ?? null;

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
            $bScopeKey = RowValues::colStr($bRow, 'scope_key');
            $bTrafficClass = RowValues::colNullableStr($bRow, 'traffic_class');
            if ($bTrafficClass !== null) {
                $origin = $targetRow === null ? null : (new SafeUrl(RowValues::colStr($targetRow, 'canonical_origin')))->origin;
                if ($bTrafficClass !== $trafficKey || $origin === null
                    || $bScopeKey !== TrafficSharePolicy::scope($origin, $trafficKey)) {
                    continue;
                }
            }
            $bTenantKey = RowValues::colStr($bRow, 'tenant_key');
            $bRevision = RowValues::colInt($bRow, 'revision');
            $constraint = new BudgetConstraint(
                scopeKey: $bScopeKey,
                emissionIntervalUs: RowValues::colInt($bRow, 'emission_interval_us'),
                burst: RowValues::colInt($bRow, 'burst'),
                maxInflight: RowValues::colInt($bRow, 'max_inflight'),
                mandatory: RowValues::colBool($bRow, 'mandatory'),
                hourLimit: RowValues::colNullableInt($bRow, 'hour_limit'),
                dayLimit: RowValues::colNullableInt($bRow, 'day_limit'),
            );
            $policyVersions['budget:'.$bTenantKey.':'.$bScopeKey] = $bRevision;

            if ($bTenantKey === TenantKey::SYSTEM || str_starts_with($bScopeKey, 'global:')) {
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
            ->join($tables['policy_revisions'].' as r', static function (JoinClause $join): void {
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
            $headIdStr = RowValues::colStr($hRow, 'head_id');
            $headData = [
                'head_id' => new Uuid($headIdStr),
                'tenant_key' => RowValues::colStr($hRow, 'tenant_key'),
                'scope_key' => RowValues::colStr($hRow, 'scope_key'),
                'revision' => RowValues::colInt($hRow, 'revision'),
                'patch' => SettingPatch::decode(RowValues::colStr($hRow, 'definition')),
            ];

            $headsById[$headIdStr] = $headData;
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
            ->where('panel_key', $panelKey)
            ->where('slot_key', $trafficKey)
            ->where(static function (Builder $query) use ($subjects): void {
                foreach ($subjects->ordered as $subject) {
                    $query->orWhere(static fn (Builder $pair): Builder => $pair->where('subject_type', $subject->type)->where('subject_id', $subject->id));
                }
            })
            ->whereNull('retired_at')
            ->orderBy('revision')
            ->get();

        $bindingsBySubjectPair = [];

        foreach ($bindingRows as $bRow) {
            $pairKey = json_encode([RowValues::colStr($bRow, 'subject_type'), RowValues::colStr($bRow, 'subject_id')], JSON_THROW_ON_ERROR);
            $bindingsBySubjectPair[$pairKey][] = $bRow;
            $policyVersions['binding:'.RowValues::colStr($bRow, 'id')] = RowValues::colInt($bRow, 'revision');
        }

        $leafPair = json_encode([$subjects->leaf->type, $subjects->leaf->id], JSON_THROW_ON_ERROR);
        if ($panelSlot !== null && ! $panelSlot->inheritDefaults && ! isset($bindingsBySubjectPair[$leafPair])) {
            throw new SubjectResolutionFailed('Explicit binding required for this slot.');
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

            $pairKey = rawurlencode($subject->type).':'.rawurlencode($subject->id);
            $entityScopeCandidates = array_values(array_unique([
                $tenantStr.'|entity:'.$pairKey,
                $tenantStr.'|entity:'.$pairKey.':'.$trafficKey,
            ]));
            if ($panelSlot === null) {
                $entityScopeCandidates[] = $tenantStr.'|entity:'.$subject->id;
            }

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
                        $defaultEditableScopeId = PolicySettingMerger::extractScopeId($eHead['scope_key'], PolicyScope::Entity);
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

            $typedPair = json_encode([$subject->type, $subject->id], JSON_THROW_ON_ERROR);
            foreach ($bindingsBySubjectPair[$typedPair] ?? [] as $bRow) {
                $boundHead = $headsById[RowValues::colStr($bRow, 'head_id')] ?? null;
                $bindingProfileId = RowValues::colNullableStr($bRow, 'profile_id');
                if ($boundHead === null || ($bindingProfileId !== null && ! isset($profileKeyById[$bindingProfileId]))) {
                    throw new SubjectResolutionFailed('Binding references a missing policy or retired profile.');
                }
                if ($panelSlot !== null && array_diff(array_keys($boundHead['patch']->changes), $panelSlot->settings) !== []) {
                    throw new SubjectResolutionFailed('Stored binding exceeds the panel field subset.');
                }

                if ($isLeaf) {
                    $editableHeadId = $boundHead['head_id'];
                }

                $boundProfileKey = $bindingProfileId !== null
                    ? $profileKeyById[$bindingProfileId]
                    : null;

                $boundProfileId = $bindingProfileId !== null
                    ? new Uuid($bindingProfileId)
                    : null;

                $boundProfileRevision = $boundProfileKey !== null
                    ? ($profileRevisionMap[$boundProfileKey] ?? 1)
                    : null;

                $patchForBinding = ! isset($addedHeadIds[$boundHead['head_id']->value])
                    ? $boundHead['patch']
                    : new SettingPatch(1, []);

                $revisionForBinding = max(RowValues::colInt($bRow, 'revision'), $boundHead['revision']);

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
    public function reattachLivePermissionExplanations(
        EffectivePolicy $policy,
        TenantKey $tenant,
        PolicyScope $editableScope,
        string $editableScopeId,
        ?Uuid $editableHeadId,
    ): EffectivePolicy {
        if ($this->access === null) {
            return $policy;
        }

        $registry = ($this->currentRegistry)();
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
    public function inspectLiveProfileRoutes(
        TenantKey $tenant,
        object $profileRow,
        Target $target,
        EffectivePolicy $intersectedPolicy,
    ): array {
        $db = ($this->requireConnection)();
        $tables = PackageSchema::tables();
        $profileId = new Uuid(RowValues::colStr($profileRow, 'id'));

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
            $epochs['profile:'.$profileId->value] = max(1, RowValues::colInt($profileHealthRow, 'health_epoch'));
        }

        foreach ($memberRows as $mRow) {
            $routeId = new Uuid(RowValues::colStr($mRow, 'route_id'));
            $allMemberRouteIds[] = $routeId->value;

            $route = $this->loadLiveRoute($db, $tenant, $routeId);

            if ($route === null || ! $route->enabled || $route->isRetired()) {
                continue;
            }

            if (
                $route->tenantKey->value !== RowValues::colStr($mRow, 'route_owner_tenant_key')
                || $route->accessGrant?->id->value !== RowValues::colNullableStr($mRow, 'shared_grant_id')
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

            $routeEpoch = $routeHealthRow !== null ? max(1, RowValues::colInt($routeHealthRow, 'health_epoch')) : 1;
            $epochs['route:'.$routeId->value] = $routeEpoch;

            if ($routeTargetHealthRow !== null) {
                $epochs['route_target:'.$routeId->value.':'.$target->id->value] = max(1, RowValues::colInt($routeTargetHealthRow, 'health_epoch'));
            }

            if ($this->isHealthRowCircuitOpen($routeHealthRow, $now) || $this->isHealthRowCircuitOpen($routeTargetHealthRow, $now)) {
                continue;
            }

            $eligibleRoutes[] = $route;
        }

        if ($epochs === []) {
            $epochs['profile:'.$profileId->value] = 1;
        }

        $profile = CatalogRowMapper::profile($profileRow, $allMemberRouteIds);

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
    public function verifySelectedRouteLive(
        TenantKey $tenant,
        Route $selectedRoute,
        PinnedPolicySnapshot $pinned,
        EffectivePolicy $intersectedPolicy,
        array $liveProfileState,
    ): NeedsReplan|ExecutionBlocked|null {
        $db = ($this->requireConnection)();
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

            if ($grantRow === null || RowValues::colNullableStr($grantRow, 'retired_at') !== null || RowValues::colInt($grantRow, 'revision') !== $selectedRoute->accessGrant->revision) {
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

            if ($endpointRow === null || RowValues::colNullableStr($endpointRow, 'retired_at') !== null) {
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

            $liveCredentialVersion = RowValues::colNullableInt($endpointRow, 'credential_version') ?? 1;

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
            $grant = new SharedRouteStorage($db)->forConsumer($tenant, $routeId);

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
        $endpointId = RowValues::colNullableStr($row, 'endpoint_id');

        if ($endpointId !== null) {
            $endpoint = $db->table($tables['endpoints'])
                ->where('tenant_key', RowValues::colStr($row, 'tenant_key'))
                ->where('id', $endpointId)
                ->first();

            if ($endpoint === null || RowValues::colNullableStr($endpoint, 'retired_at') !== null) {
                return null;
            }
        }

        return CatalogRowMapper::route(
            $row,
            $grant,
            $endpoint === null ? 1 : (RowValues::colNullableInt($endpoint, 'credential_version') ?? 1),
            $endpoint === null ? null : RowValues::colInt($endpoint, 'revision'),
        );
    }

    private function isHealthRowCircuitOpen(?object $healthRow, DateTimeImmutable $now): bool
    {
        if ($healthRow === null) {
            return false;
        }

        if (RowValues::colStr($healthRow, 'state') === 'open') {
            $openUntil = RowValues::colNullableStr($healthRow, 'open_until');
            if ($openUntil === null) {
                return true;
            }

            return new DateTimeImmutable($openUntil) > $now;
        }

        return false;
    }

    /**
     * @param  list<string>  $budgetScopeKeys
     */
    public function hasExhaustedLiveQuotaWindow(Connection $db, TenantKey $tenant, array $budgetScopeKeys): ?string
    {
        if ($budgetScopeKeys === []) {
            return null;
        }

        $tables = PackageSchema::tables();
        $exhaustedRow = $db->table($tables['quota_windows'].' as q')
            ->join($tables['budgets'].' as b', static function (JoinClause $join): void {
                $join->on('b.tenant_key', '=', 'q.tenant_key')
                    ->on('b.id', '=', 'q.budget_id');
            })
            ->whereIn('b.tenant_key', array_values(array_unique([$tenant->value, TenantKey::SYSTEM])))
            ->whereIn('b.scope_key', $budgetScopeKeys)
            ->whereRaw('q.starts_at <= clock_timestamp() AND q.ends_at > clock_timestamp()')
            ->whereRaw('q.reserved + q.spent >= q.quota_limit')
            ->select(['b.scope_key'])
            ->first();

        return $exhaustedRow !== null ? RowValues::colStr($exhaustedRow, 'scope_key') : null;
    }

    /**
     * @return array{fields: list<string>, layers: list<string>}
     */
    public function detectPolicyDrift(PinnedPolicySnapshot $pinned, EffectivePolicy $live): array
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

    public function canEditField(
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
}
