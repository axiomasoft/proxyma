<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use Illuminate\Database\Connection;
use LogicException;
use Packman\Attributes\Internal;
use Proxyma\Application\Catalog\CatalogNotFound;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;

/** @internal Первый package lock owning transaction; перед network и budgets. */
#[Internal]
final readonly class OperationMutex
{
    public function __construct(private Connection $db) {}

    /** @param list<Uuid> $operationIds */
    public function lock(TenantKey $tenant, array $operationIds): void
    {
        if ($this->db->transactionLevel() < 1) {
            throw new LogicException('Operation root requires an owning transaction on this connection.');
        }
        $ids = array_values(array_unique(array_map(static fn (Uuid $id): string => $id->value, $operationIds)));
        sort($ids, SORT_STRING);
        foreach ($ids as $id) {
            if (PackageSchema::query($this->db, 'operations')->where('tenant_key', $tenant->value)->where('id', $id)->lockForUpdate()->first() === null) {
                throw new CatalogNotFound('Operation not found in tenant scope.');
            }
        }
    }
}
