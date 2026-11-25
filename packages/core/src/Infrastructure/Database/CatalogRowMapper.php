<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use DateTimeImmutable;
use Packman\Attributes\Internal;
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
use Proxyma\Internal\Database\RowValues;

/** @internal */
#[Internal]
final class CatalogRowMapper
{
    public static function endpoint(object $row): Endpoint
    {
        $credentialRef = RowValues::colNullableStr($row, 'credential_ref');

        return new Endpoint(
            new Uuid(RowValues::colStr($row, 'id')),
            new TenantKey(RowValues::colStr($row, 'tenant_key')),
            ProxyProtocol::from(RowValues::colStr($row, 'protocol')),
            RowValues::colStr($row, 'host'),
            RowValues::colInt($row, 'port'),
            $credentialRef === null ? null : new CredentialRef($credentialRef, RowValues::colInt($row, 'credential_version')),
            RowValues::colInt($row, 'revision'),
            self::retiredAt($row),
        );
    }

    public static function route(
        object $row,
        ?SharedRouteGrant $grant = null,
        ?int $credentialVersion = null,
        ?int $endpointRevision = null,
    ): Route {
        $endpointId = RowValues::colNullableStr($row, 'endpoint_id');

        return new Route(
            new Uuid(RowValues::colStr($row, 'id')),
            new TenantKey(RowValues::colStr($row, 'tenant_key')),
            RowValues::colStr($row, 'route_key'),
            RouteKind::from(RowValues::colStr($row, 'kind')),
            $endpointId === null ? null : new Uuid($endpointId),
            RowValues::colBool($row, 'enabled'),
            new EgressIdentity(RowValues::colStr($row, 'egress_key'), RowValues::colBool($row, 'conservative_pool')),
            RowValues::colNullableStr($row, 'independence_group'),
            $credentialVersion ?? (RowValues::colNullableInt($row, 'credential_version') ?? 1),
            RowValues::colInt($row, 'revision'),
            RowValues::colBool($row, 'shared_grant'),
            self::retiredAt($row),
            $grant,
            $endpointRevision ?? RowValues::colNullableInt($row, 'endpoint_revision'),
        );
    }

    /** @param list<string>|null $memberRouteIds */
    public static function profile(object $row, ?array $memberRouteIds = null): RouteProfile
    {
        $members = $memberRouteIds ?? RowValues::strList(RowValues::col($row, 'member_route_ids'));

        return new RouteProfile(
            new Uuid(RowValues::colStr($row, 'id')),
            new TenantKey(RowValues::colStr($row, 'tenant_key')),
            RowValues::colStr($row, 'profile_key'),
            array_map(static fn (string $id): Uuid => new Uuid($id), $members),
            RowValues::colStr($row, 'strategy_key'),
            RowValues::colInt($row, 'revision'),
        );
    }

    public static function target(object $row): Target
    {
        return new Target(
            new Uuid(RowValues::colStr($row, 'id')),
            new TenantKey(RowValues::colStr($row, 'tenant_key')),
            RowValues::colStr($row, 'target_key'),
            CanonicalOrigin::fromUrl(RowValues::colStr($row, 'canonical_origin')),
            RowValues::colStr($row, 'destination_policy_key'),
            RowValues::colInt($row, 'revision'),
            self::retiredAt($row),
        );
    }

    private static function retiredAt(object $row): ?DateTimeImmutable
    {
        $retiredAt = RowValues::colNullableStr($row, 'retired_at');

        return $retiredAt === null ? null : new DateTimeImmutable($retiredAt);
    }
}
