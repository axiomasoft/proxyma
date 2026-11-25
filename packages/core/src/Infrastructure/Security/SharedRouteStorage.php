<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Security;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use Packman\Attributes\Internal;
use Proxyma\Domain\Catalog\SharedRouteGrant;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Internal\Database\RowValues;

/** @internal */
#[Internal]
final readonly class SharedRouteStorage
{
    public function __construct(private Connection $db) {}

    public function forConsumer(TenantKey $consumer, Uuid $routeId): ?SharedRouteGrant
    {
        $row = PackageSchema::query($this->db, 'shared_route_grants')
            ->where('consumer_tenant_key', $consumer->value)->where('route_id', $routeId->value)->whereNull('retired_at')->first();

        return $row === null ? null : self::map($row);
    }

    public static function map(object $row): SharedRouteGrant
    {
        $retiredAt = RowValues::colNullableStr($row, 'retired_at');

        return new SharedRouteGrant(
            new Uuid(RowValues::colStr($row, 'id')),
            new TenantKey(RowValues::colStr($row, 'tenant_key')),
            new TenantKey(RowValues::colStr($row, 'consumer_tenant_key')),
            new Uuid(RowValues::colStr($row, 'route_id')),
            RowValues::colInt($row, 'revision'),
            $retiredAt === null ? null : new DateTimeImmutable($retiredAt),
        );
    }
}
