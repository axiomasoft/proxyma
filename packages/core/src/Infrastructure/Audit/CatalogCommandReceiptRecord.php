<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Audit;

use Illuminate\Database\Eloquent\Attributes\Table;
use Proxyma\Infrastructure\Eloquent\AuthorityRecord;

/** @internal Отдельный replay projection, не журнал для UI/экспорта. */
#[Table(name: 'pxm_catalog_command_receipts')]
final class CatalogCommandReceiptRecord extends AuthorityRecord {}
