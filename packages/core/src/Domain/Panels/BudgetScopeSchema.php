<?php

declare(strict_types=1);

namespace Proxyma\Domain\Panels;

use Packman\Attributes\Api;
use Proxyma\Domain\Policy\BudgetScopeKey;
use Proxyma\Domain\Policy\TenantKey;

#[Api(since: '1.0')]
final readonly class BudgetScopeSchema
{
    /** @param list<string> $keys Exact trusted legacy scope keys.
     * @param  list<string>  $sharedTenants  Explicit consumers of shared system budgets.
     */
    public function __construct(public string $namespace, public int $version = 1, public array $keys = [], public array $sharedTenants = [])
    {
        PanelSlot::identifier($namespace);
        /** @var array<array-key, string> $keys */
        /** @var array<array-key, string> $sharedTenants */
        if ($version < 1 || ! array_is_list($keys) || ! array_is_list($sharedTenants)) {
            throw new DefinitionError('Invalid budget scope schema.');
        }
        foreach ($keys as $key) {
            new BudgetScopeKey($key);
        }
        foreach ($sharedTenants as $tenant) {
            new TenantKey($tenant);
        }
    }

    public function prefix(TenantKey $tenant): string
    {
        return 'ext:'.$this->namespace.':v'.$this->version.':'.hash('sha256', $tenant->value).':';
    }

    public function allows(string $key, TenantKey $tenant): bool
    {
        return in_array($key, $this->keys, true) || str_starts_with($key, $this->prefix($tenant));
    }
}
