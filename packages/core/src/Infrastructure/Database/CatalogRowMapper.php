<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use DateTimeImmutable;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\CredentialRef;
use Proxyma\Domain\Catalog\EgressIdentity;
use Proxyma\Domain\Catalog\Endpoint;
use Proxyma\Domain\Catalog\ProxyProtocol;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Catalog\RouteProfile;
use Proxyma\Domain\Catalog\SharedRouteGrant;
use Proxyma\Domain\Catalog\Target;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;

/** @internal */
final class CatalogRowMapper
{
    public static function endpoint(object $row): Endpoint
    {
        return new Endpoint(new Uuid($row->id), new TenantKey($row->tenant_key), ProxyProtocol::from($row->protocol), $row->host, (int) $row->port,
            $row->credential_ref === null ? null : new CredentialRef($row->credential_ref, (int) $row->credential_version),
            (int) $row->revision, self::retiredAt($row));
    }

    public static function route(object $row, ?SharedRouteGrant $grant = null): Route
    {
        return new Route(new Uuid($row->id), new TenantKey($row->tenant_key), $row->route_key, RouteKind::from($row->kind),
            $row->endpoint_id === null ? null : new Uuid($row->endpoint_id), (bool) $row->enabled,
            new EgressIdentity($row->egress_key, (bool) $row->conservative_pool), $row->independence_group,
            (int) ($row->credential_version ?? 1), (int) $row->revision, (bool) $row->shared_grant, self::retiredAt($row), $grant,
            isset($row->endpoint_revision) ? (int) $row->endpoint_revision : null);
    }

    public static function profile(object $row): RouteProfile
    {
        return new RouteProfile(new Uuid($row->id), new TenantKey($row->tenant_key), $row->profile_key,
            array_map(static fn (string $id): Uuid => new Uuid($id), $row->member_route_ids), $row->strategy_key, (int) $row->revision);
    }

    public static function target(object $row): Target
    {
        return new Target(new Uuid($row->id), new TenantKey($row->tenant_key), $row->target_key,
            CanonicalOrigin::fromUrl($row->canonical_origin), $row->destination_policy_key, (int) $row->revision, self::retiredAt($row));
    }

    private static function retiredAt(object $row): ?DateTimeImmutable
    {
        return $row->retired_at === null ? null : new DateTimeImmutable($row->retired_at);
    }
}
