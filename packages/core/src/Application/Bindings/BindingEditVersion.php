<?php

declare(strict_types=1);

namespace Proxyma\Application\Bindings;

use Illuminate\Database\Connection;
use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Application\Policy\SettingRegistry;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\PackageSchema;

/** Любое изменение tenant/system policy требует перезагрузки открытой формы. */
final readonly class BindingEditVersion
{
    public function __construct(private Connection $db, private DefinitionRegistry $definitions, private SettingRegistry $settings) {}

    public function current(TenantKey $tenant): string
    {
        $heads = $this->db->table(PackageSchema::tables()['policy_heads'])
            ->whereIn('tenant_key', array_unique([$tenant->value, TenantKey::SYSTEM]))
            ->orderBy('tenant_key')->orderBy('id')->get(['tenant_key', 'id', 'revision', 'current_revision_id'])->all();

        return hash('sha256', json_encode([$tenant->value, $heads, $this->definitions->fingerprint(),
            $this->settings->fingerprint(), config('proxyma.policy', [])], JSON_THROW_ON_ERROR));
    }
}
