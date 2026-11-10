<?php

declare(strict_types=1);

namespace Proxyma\Filament\Actions;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Catalog\CatalogNotFound;
use Proxyma\Application\Catalog\RotateCredentials;
use Proxyma\Application\Routing\StrategyRegistry;
use Proxyma\Contracts\CatalogCommands;
use Proxyma\Contracts\CatalogQueries;
use Proxyma\Contracts\TenantContextResolver;
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
use SensitiveParameter;

class SaveCatalog
{
    public function __construct(private CatalogCommands $commands, private CatalogQueries $queries,
        private TenantContextResolver $tenants, private CatalogAccess $access) {}

    public function authorize(string $kind, string $id, string $operation = 'upsert'): void
    {
        $context = $this->tenants->resolve();
        $this->access->authorize($context->tenant, "catalog.{$kind}.{$operation}", new Uuid($id), $context->actor);
    }

    public function save(string $kind, string $id, int $revision, array $data): Endpoint|Route|RouteProfile
    {
        $this->authorize($kind, $id);
        $context = $this->tenants->resolve();
        $uuid = new Uuid($id);
        $actor = new CatalogMutationActor($context->actor, $data['reason'], $revision, Uuid::random()->value);
        if ($kind === 'endpoint') {
            $previous = $this->queries->findEndpoint($context->tenant, $uuid);
            if ($revision > 0 && $previous === null) {
                throw new CatalogNotFound;
            }

            return $this->commands->upsertEndpoint(new UpsertEndpointCommand($uuid, $context->tenant,
                ProxyProtocol::from($data['protocol']), $data['host'], (int) $data['port'], $previous?->credential, $actor));
        }
        if ($kind === 'route') {
            $previous = $this->queries->findRoute($context->tenant, $uuid);
            if (($revision > 0 && $previous === null) || ($previous !== null && ! $previous->tenantKey->equals($context->tenant))) {
                throw new CatalogNotFound;
            }

            return $this->commands->upsertRoute(new UpsertRouteCommand($uuid, $context->tenant, $data['route_key'],
                RouteKind::from($data['kind']), empty($data['endpoint_id']) ? null : new Uuid($data['endpoint_id']),
                $data['enabled'], new EgressIdentity($data['egress_key'], $data['conservative_pool']),
                $data['independence_group'] ?: null, $previous?->sharedGrant ?? false, $actor));
        }
        if ($kind !== 'profile') {
            throw new InvalidArgumentException('Unknown catalog section.');
        }
        if ($revision > 0 && $this->queries->findProfile($context->tenant, $uuid) === null) {
            throw new CatalogNotFound;
        }
        try {
            app(StrategyRegistry::class)->get($data['strategy_key']);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['data.strategy_key' => 'Стратегия не зарегистрирована.']);
        }

        return $this->commands->upsertProfile(new UpsertRouteProfileCommand($uuid, $context->tenant, $data['profile_key'],
            array_map(static fn (string $id): Uuid => new Uuid($id), $data['member_route_ids']), $data['strategy_key'], $actor));
    }

    public function rotate(string $id, int $revision, string $reason, #[SensitiveParameter] array $credentials): Endpoint
    {
        $this->authorize('endpoint', $id, 'rotate-credentials');
        $password = $credentials['password'];
        if (trim($password) === '' || preg_match('/^[*•●]+$/u', trim($password)) === 1) {
            throw ValidationException::withMessages(['credentialData.password' => 'Введите новый секрет; маска не сохраняется.']);
        }
        $context = $this->tenants->resolve();

        return app(RotateCredentials::class)->handle(new Uuid($id), $context->tenant,
            ($credentials['username'] ?? '') ?: null, $password,
            new CatalogMutationActor($context->actor, $reason, $revision, Uuid::random()->value));
    }
}
