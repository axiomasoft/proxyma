<?php

declare(strict_types=1);

namespace Proxyma\Filament\Actions;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Packman\Attributes\Api;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Catalog\CatalogNotFound;
use Proxyma\Application\Catalog\RotateCredentials;
use Proxyma\Application\Routing\StrategyRegistry;
use Proxyma\Contracts\Authorization\TenantContextResolver;
use Proxyma\Contracts\Catalog\CatalogCommands;
use Proxyma\Contracts\Catalog\CatalogQueries;
use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Catalog\EgressIdentity;
use Proxyma\Domain\Catalog\Endpoint;
use Proxyma\Domain\Catalog\ProxyProtocol;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Catalog\RouteProfile;
use Proxyma\Domain\Catalog\UpsertEndpointCommand;
use Proxyma\Domain\Catalog\UpsertRouteCommand;
use Proxyma\Domain\Catalog\UpsertRouteProfileCommand;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Filament\Internal\FilamentValue;
use SensitiveParameter;

#[Api(since: '1.0')]
class SaveCatalog
{
    public function __construct(private CatalogCommands $commands, private CatalogQueries $queries,
        private TenantContextResolver $tenants, private CatalogAccess $access) {}

    public function authorize(string $kind, string $id, string $operation = 'upsert'): void
    {
        $context = $this->tenants->resolve();
        $this->access->authorize($context->tenant, "catalog.{$kind}.{$operation}", new Uuid($id), $context->actor);
    }

    /** @param array<string, mixed> $data */
    public function save(string $kind, string $id, int $revision, array $data, ?string $authorityVersion = null): Endpoint|Route|RouteProfile
    {
        $this->authorize($kind, $id);
        $context = $this->tenants->resolve();
        $uuid = new Uuid($id);
        $actor = new CatalogMutationActor($context->actor, FilamentValue::str($data['reason'] ?? null), $revision, Uuid::random()->value, $authorityVersion);
        if ($kind === 'endpoint') {
            $previous = $this->queries->findEndpoint($context->tenant, $uuid);
            if ($revision > 0 && $previous === null) {
                throw new CatalogNotFound;
            }

            return $this->commands->upsertEndpoint(new UpsertEndpointCommand($uuid, $context->tenant,
                ProxyProtocol::from(FilamentValue::str($data['protocol'] ?? null)), FilamentValue::str($data['host'] ?? null),
                FilamentValue::int($data['port'] ?? null), $previous?->credential, $actor));
        }
        if ($kind === 'route') {
            $previous = $this->queries->findRoute($context->tenant, $uuid);
            if (($revision > 0 && $previous === null) || ($previous !== null && ! $previous->tenantKey->equals($context->tenant))) {
                throw new CatalogNotFound;
            }
            $endpointId = FilamentValue::nullableStr($data['endpoint_id'] ?? null);

            return $this->commands->upsertRoute(new UpsertRouteCommand($uuid, $context->tenant, FilamentValue::str($data['route_key'] ?? null),
                RouteKind::from(FilamentValue::str($data['kind'] ?? null)), $endpointId === null ? null : new Uuid($endpointId),
                FilamentValue::bool($data['enabled'] ?? null), new EgressIdentity(FilamentValue::str($data['egress_key'] ?? null), FilamentValue::bool($data['conservative_pool'] ?? null)),
                FilamentValue::nullableStr($data['independence_group'] ?? null), $previous->sharedGrant ?? false, $actor));
        }
        if ($kind !== 'profile') {
            throw new InvalidArgumentException('Unknown catalog section.');
        }
        if ($revision > 0 && $this->queries->findProfile($context->tenant, $uuid) === null) {
            throw new CatalogNotFound;
        }
        $strategyKey = FilamentValue::str($data['strategy_key'] ?? null);
        try {
            app(StrategyRegistry::class)->get($strategyKey);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['data.strategy_key' => 'Стратегия не зарегистрирована.']);
        }

        return $this->commands->upsertProfile(new UpsertRouteProfileCommand($uuid, $context->tenant, FilamentValue::str($data['profile_key'] ?? null),
            array_map(static fn (string $routeId): Uuid => new Uuid($routeId), FilamentValue::strList($data['member_route_ids'] ?? [])), $strategyKey, $actor));
    }

    /** @param array<string, mixed> $credentials */
    public function rotate(string $id, int $revision, string $reason, #[SensitiveParameter] array $credentials, ?string $authorityVersion = null): Endpoint
    {
        $this->authorize('endpoint', $id, 'rotate-credentials');
        $password = FilamentValue::str($credentials['password'] ?? null);
        if (trim($password) === '' || preg_match('/^[*•●]+$/u', trim($password)) === 1) {
            throw ValidationException::withMessages(['credentialData.password' => 'Введите новый секрет; маска не сохраняется.']);
        }
        $context = $this->tenants->resolve();

        return app(RotateCredentials::class)->handle(new Uuid($id), $context->tenant,
            FilamentValue::nullableStr($credentials['username'] ?? null), $password,
            new CatalogMutationActor($context->actor, $reason, $revision, Uuid::random()->value, $authorityVersion));
    }
}
