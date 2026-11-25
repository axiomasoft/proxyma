<?php

declare(strict_types=1);

namespace Proxyma\Internal\Policy;

use Closure;
use Packman\Attributes\Internal;
use Proxyma\Application\Policy\SettingRegistry;
use Proxyma\Domain\Execution\ContentHash;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\BudgetConstraint;
use Proxyma\Domain\Policy\FieldExplanation;
use Proxyma\Domain\Policy\FieldProvenanceStep;
use Proxyma\Domain\Policy\PatchOperation;
use Proxyma\Domain\Policy\PolicyLayer;
use Proxyma\Domain\Policy\PolicyLayerKind;
use Proxyma\Domain\Policy\PolicyScope;
use Proxyma\Domain\Policy\SettingDescriptor;
use Proxyma\Domain\Policy\SettingMerge;
use Proxyma\Domain\Policy\SettingPatch;
use Proxyma\Domain\Policy\SettingType;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Internal\Database\RowValues;

/** Deterministic 10-layer policy merge, clamping, revision, and hash computation engine. */
#[Internal]
final readonly class PolicySettingMerger
{
    /**
     * @param  list<PolicyLayer>  $orderedLayers
     * @param  array<string, mixed>  $hardBounds
     * @param  array{allowed: list<string>|null, source_scope: string, source_revision: int}  $allowedProfilesState
     * @param  (Closure(SettingDescriptor, PolicyScope, string): bool)|null  $permissionChecker
     * @return array{value: mixed, disabled: bool, explanation: FieldExplanation}
     */
    public function resolveSingleDescriptor(
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

            $snapshotsBeforeScopeKey[$scopeTrackKey] ??= [
                'value' => $currentValue,
                'scope' => $sourceScope,
                'layer' => $sourceLayer,
                'revision' => $sourceRevision,
                'explicit' => $hasExplicitPolicyValue,
                'disabled' => $disabled,
            ];

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
                $val = RowValues::int($item);

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

        $currentList = is_array($currentValue) ? RowValues::intList($currentValue) : [];

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

        $numericValue = RowValues::int($newValue);
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

        $currentInt = $currentValue === null ? null : RowValues::int($currentValue);

        if ($currentInt === null || $numericValue < $currentInt) {
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

        if ($numericValue > $currentInt) {
            return [
                'value' => $currentInt,
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
            'value' => $currentInt,
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
            ? array_values(array_unique(RowValues::strList($currentValue)))
            : [];

        $incomingList = is_array($newValue)
            ? array_values(array_unique(RowValues::strList($newValue)))
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
            ? array_values(array_unique(RowValues::strList($newValue)))
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

        $currentList = is_array($currentValue) ? array_values(array_unique(RowValues::strList($currentValue))) : [];
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
    public function resolveBudgets(array $orderedLayers): array
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
    public function normalizeLayers(array $layers): array
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
            static function (array $a, array $b): int {
                $cmp = $a['order'] <=> $b['order'];

                return $cmp !== 0 ? $cmp : $a['index'] <=> $b['index'];
            },
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

        return $layerRank === $targetRank
        && $layer->kind === PolicyLayerKind::OrderedEntityAncestry
        && self::extractScopeId($layer->scopeKey, PolicyScope::Entity) !== $targetScopeId;
    }

    public static function extractScopeId(string $scopeKey, PolicyScope $scope): string
    {
        $prefix = $scope->value.':';

        return str_starts_with($scopeKey, $prefix)
            ? substr($scopeKey, strlen($prefix))
            : $scopeKey;
    }

    /**
     * @param  list<PolicyLayer>  $orderedLayers
     */
    public function computeEffectiveRevision(array $orderedLayers): int
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
    public function computePolicyHash(
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
    public function injectPreviewLayer(
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
}
