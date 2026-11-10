<?php

declare(strict_types=1);

namespace Proxyma\Application\Extensions;

use Proxyma\Application\Policy\SettingRegistry;
use Proxyma\Contracts\LockedAdmissionGuard;
use Proxyma\Contracts\OutcomePolicy;
use Proxyma\Contracts\RouteSelectionStrategy;
use Proxyma\Contracts\SqlConnectionParticipant;
use Proxyma\Domain\Execution\AdmissionLockTier;
use Proxyma\Domain\Panels\DefinitionError;
use Proxyma\Domain\Panels\ExtensionDefinition;
use Proxyma\Domain\Panels\ExtensionKind;
use Proxyma\Domain\Panels\PanelSlot;
use Proxyma\Domain\Panels\SubjectResolverDefinition;
use Proxyma\Domain\Policy\PanelDefinition;
use Proxyma\Domain\Policy\PolicyDefinition;
use Proxyma\Domain\Policy\PolicyScope;

final class DefinitionRegistry
{
    /** @var array<string, PanelDefinition> */
    private array $panels = [];

    /** @var array<string, SubjectResolverDefinition> */
    private array $resolvers = [];

    /** @var array<string, ExtensionDefinition> */
    private array $extensions = [];

    /** @var array<string, int> */
    private array $capabilities = [];

    private ?string $fingerprint = null;

    /** @var array<string, PolicyDefinition> */
    private array $policies = [];

    public function __construct(private readonly SettingRegistry $settings) {}

    public function registerPanel(PanelDefinition $definition): void
    {
        $this->writable($definition->id, $this->panels);
        $this->panels[$definition->id] = $definition;
    }

    public function registerSubjectResolver(SubjectResolverDefinition $definition): void
    {
        $this->writable($definition->id, $this->resolvers);
        $this->resolvers[$definition->id] = $definition;
    }

    public function registerExtension(ExtensionDefinition $definition): void
    {
        $this->writable($definition->id, $this->extensions);
        $this->extensions[$definition->id] = $definition;
    }

    public function registerCapability(string $id, int $version = 1): void
    {
        PanelSlot::identifier($id);
        $this->writable($id, $this->capabilities);
        if ($version < 1) {
            throw new DefinitionError('Capability version must be positive.');
        }
        $this->capabilities[$id] = $version;
    }

    public function freeze(): string
    {
        if ($this->fingerprint !== null) {
            return $this->fingerprint;
        }
        $data = ['settings' => $this->settings->fingerprint(), 'panels' => [], 'resolvers' => [], 'extensions' => [], 'capabilities' => $this->capabilities];
        ksort($data['capabilities'], SORT_STRING);
        foreach ($this->resolvers as $id => $resolver) {
            $types = $resolver->types;
            sort($types, SORT_STRING);
            $data['resolvers'][$id] = [$resolver->resolver::class, $resolver->version, $types];
        }
        foreach ($this->panels as $id => $panel) {
            if (! isset($this->resolvers[$panel->subjectResolver])) {
                throw new DefinitionError('Missing panel subject resolver.');
            }
            $required = $panel->requiredCapabilities;
            sort($required, SORT_STRING);
            foreach ($required as $capability) {
                if (! isset($this->capabilities[$capability])) {
                    throw new DefinitionError('Missing panel capability.');
                }
            }
            $slots = [];
            foreach ($panel->slots as $key => $slot) {
                $fields = $slot->settings;
                sort($fields, SORT_STRING);
                foreach ($fields as $field) {
                    $descriptor = $this->settings->descriptor($field);
                    if (! in_array(PolicyScope::Entity, $descriptor->allowedScopes, true)) {
                        throw new DefinitionError('Panel field does not allow entity scope.');
                    }
                }
                $this->settings->validate($slot->defaults, PolicyScope::Entity, stored: true);
                if (array_diff(array_keys($slot->defaults->changes), $fields) !== []) {
                    throw new DefinitionError('Panel defaults exceed field subset.');
                }
                $slots[$key] = [$fields, $slot->defaults->encode(), $slot->inheritDefaults];
            }
            $permissions = $panel->permissions;
            ksort($permissions, SORT_STRING);
            $data['panels'][$id] = [$panel->version, $panel->subjectResolver, $slots, $required, $permissions];
        }
        $tiers = array_flip(array_map(static fn (AdmissionLockTier $tier): string => $tier->value, AdmissionLockTier::cases()));
        $positions = [];
        $visiting = [];
        $visited = [];
        $policies = [];
        foreach ($this->extensions as $id => $extension) {
            $this->visit($id, $visiting, $visited);
            foreach ($extension->requiredCapabilities as $capability) {
                if (! isset($this->capabilities[$capability])) {
                    throw new DefinitionError('Missing extension capability.');
                }
            }
            $implementation = $extension->implementation;
            if ($extension->kind === ExtensionKind::PolicyDefinitions) {
                foreach ($implementation->definitions() as $policy) {
                    if (! $policy instanceof PolicyDefinition || isset($policies[$policy->policyKey])) {
                        throw new DefinitionError('Invalid or duplicate policy definition.');
                    }
                    $policies[$policy->policyKey] = $policy;
                }
            }
            if ($implementation instanceof LockedAdmissionGuard
                && ($extension->tier !== AdmissionLockTier::AdapterGuard || ! $implementation instanceof SqlConnectionParticipant
                    || $implementation->tier() !== $extension->tier)) {
                throw new DefinitionError('Guard requires a matching adapter_guard tier and SQL participant binding.');
            }
            if ($extension->kind === ExtensionKind::BudgetScopeContributor && $extension->budgetSchema === null) {
                throw new DefinitionError('Contributor requires a declared budget namespace and schema.');
            }
            if (($implementation instanceof RouteSelectionStrategy || $implementation instanceof OutcomePolicy) && $implementation->key() !== $id) {
                throw new DefinitionError('Implementation key must match its registered ID.');
            }
            $position = $extension->kind->value.':'.($extension->tier === null ? '' : $extension->tier->value).':'.$extension->priority;
            if (isset($positions[$position])) {
                throw new DefinitionError('Extension priority conflict at the declared tier.');
            }
            $positions[$position] = true;
            foreach ($extension->dependencies as $dependencyId) {
                $dependency = $this->extensions[$dependencyId];
                if ($dependency->kind !== $extension->kind) {
                    throw new DefinitionError('Dependencies must belong to the same finite extension kind.');
                }
                if ($extension->tier !== null && $dependency->tier !== null
                    && $tiers[$dependency->tier->value] > $tiers[$extension->tier->value]) {
                    throw new DefinitionError('Dependency requires a later lock tier.');
                }
                if ($extension->kind === $dependency->kind && $extension->tier === $dependency->tier
                    && $dependency->priority >= $extension->priority) {
                    throw new DefinitionError('Dependency conflicts with extension priority.');
                }
            }
            $dependencies = $extension->dependencies;
            sort($dependencies, SORT_STRING);
            $required = $extension->requiredCapabilities;
            sort($required, SORT_STRING);
            $schema = $extension->budgetSchema;
            $keys = $schema?->keys ?? [];
            $grants = $schema?->sharedTenants ?? [];
            sort($keys, SORT_STRING);
            sort($grants, SORT_STRING);
            $data['extensions'][$id] = [$extension->kind->value, $implementation::class, $extension->version, $dependencies, $extension->priority, $extension->tier?->value, $required,
                $schema === null ? null : [$schema->namespace, $schema->version, $keys, $grants]];
        }
        foreach (['panels', 'resolvers', 'extensions'] as $kind) {
            ksort($data[$kind], SORT_STRING);
        }
        ksort($policies, SORT_STRING);
        $data['policies'] = $policies;
        $this->policies = $policies;
        $this->fingerprint = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return $this->fingerprint;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint ?? throw new DefinitionError('Registry must be frozen before use.');
    }

    public function policy(string $id): PolicyDefinition
    {
        $this->fingerprint();

        return $this->policies[$id] ?? throw new DefinitionError('Unknown policy definition.');
    }

    public function hasPanel(string $id): bool
    {
        $this->fingerprint();

        return isset($this->panels[$id]);
    }

    public function panel(string $id): PanelDefinition
    {
        $this->fingerprint();

        return $this->panels[$id] ?? throw new DefinitionError('Unknown panel ID.');
    }

    public function subjectResolver(string $id): SubjectResolverDefinition
    {
        $this->fingerprint();

        return $this->resolvers[$id] ?? throw new DefinitionError('Unknown subject resolver ID.');
    }

    /** @param array<array-key, string> $ids
     * @return list<ExtensionDefinition>
     */
    public function selectExtensions(ExtensionKind $kind, array $ids): array
    {
        $this->fingerprint();
        if (! array_is_list($ids) || count(array_unique($ids)) !== count($ids)) {
            throw new DefinitionError('Extension selection must be unique IDs.');
        }
        $selected = [];
        foreach ($ids as $id) {
            $definition = $this->extensions[$id] ?? throw new DefinitionError('Unknown extension ID.');
            if ($definition->kind !== $kind || array_diff($definition->dependencies, $ids) !== []) {
                throw new DefinitionError('Wrong extension kind or missing selected dependency.');
            }
            $selected[] = $definition;
        }
        $tiers = array_flip(array_map(static fn (AdmissionLockTier $tier): string => $tier->value, AdmissionLockTier::cases()));
        usort($selected, static fn (ExtensionDefinition $a, ExtensionDefinition $b): int => [($a->tier === null ? -1 : $tiers[$a->tier->value]), $a->priority, $a->id] <=> [($b->tier === null ? -1 : $tiers[$b->tier->value]), $b->priority, $b->id]);

        return $selected;
    }

    /** @param array<string, mixed> $existing */
    private function writable(string $id, array $existing): void
    {
        if ($this->fingerprint !== null || isset($existing[$id])) {
            throw new DefinitionError('Registry is frozen or the ID is duplicated.');
        }
    }

    /** @param array<string, bool> $visiting
     * @param  array<string, bool>  $visited
     */
    private function visit(string $id, array &$visiting, array &$visited): void
    {
        if (isset($visiting[$id])) {
            throw new DefinitionError('Extension dependency cycle.');
        }
        if (isset($visited[$id])) {
            return;
        }
        $definition = $this->extensions[$id] ?? throw new DefinitionError('Missing extension dependency.');
        $visiting[$id] = true;
        foreach ($definition->dependencies as $dependency) {
            $this->visit($dependency, $visiting, $visited);
        }
        unset($visiting[$id]);
        $visited[$id] = true;
    }
}
