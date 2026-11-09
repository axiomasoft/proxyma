<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Security;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use Proxyma\Domain\Catalog\SharedRouteGrant;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\PackageSchema;

/** @internal */
final readonly class SharedRouteStorage
{
    public function __construct(private Connection $db) {}

    public function forConsumer(TenantKey $consumer, Uuid $routeId): ?SharedRouteGrant
    {
        $row = $this->db->table(PackageSchema::tables()['shared_route_grants'])
            ->where('consumer_tenant_key', $consumer->value)->where('route_id', $routeId->value)->whereNull('retired_at')->first();

        return $row === null ? null : self::map($row);
    }

    public static function map(object $row): SharedRouteGrant
    {
        return new SharedRouteGrant(new Uuid($row->id), new TenantKey($row->tenant_key), new TenantKey($row->consumer_tenant_key),
            new Uuid($row->route_id), (int) $row->revision, $row->retired_at === null ? null : new DateTimeImmutable($row->retired_at));
    }
}
