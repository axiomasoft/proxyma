<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

enum PolicyLayerKind: string
{
    case PackageDefaults = 'package_defaults';
    case ProjectDefaults = 'project_defaults';
    case GlobalDb = 'global_db';
    case TenantDb = 'tenant_db';
    case PanelDefinitionDefaults = 'panel_definition_defaults';
    case PanelDb = 'panel_db';
    case OrderedEntityAncestry = 'ordered_entity_ancestry';
    case EntityBinding = 'entity_binding';
    case PinnedExecutionSnapshot = 'pinned_execution_snapshot';
    case RequestNarrowing = 'request_narrowing';

    public function precedence(): int
    {
        return match ($this) {
            self::PackageDefaults => 1,
            self::ProjectDefaults => 2,
            self::GlobalDb => 3,
            self::TenantDb => 4,
            self::PanelDefinitionDefaults => 5,
            self::PanelDb => 6,
            self::OrderedEntityAncestry => 7,
            self::EntityBinding => 8,
            self::PinnedExecutionSnapshot => 9,
            self::RequestNarrowing => 10,
        };
    }

    public function policyScope(): ?PolicyScope
    {
        return match ($this) {
            self::GlobalDb => PolicyScope::Global,
            self::TenantDb => PolicyScope::Tenant,
            self::PanelDb => PolicyScope::Panel,
            self::OrderedEntityAncestry, self::EntityBinding => PolicyScope::Entity,
            default => null,
        };
    }

    public function isPostBindingNarrowing(): bool
    {
        return $this === self::PinnedExecutionSnapshot || $this === self::RequestNarrowing;
    }

    /**
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [
            self::PackageDefaults,
            self::ProjectDefaults,
            self::GlobalDb,
            self::TenantDb,
            self::PanelDefinitionDefaults,
            self::PanelDb,
            self::OrderedEntityAncestry,
            self::EntityBinding,
            self::PinnedExecutionSnapshot,
            self::RequestNarrowing,
        ];
    }
}
