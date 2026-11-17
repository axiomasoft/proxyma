<?php

declare(strict_types=1);

namespace Proxyma\Tests\Support;

use DateTimeImmutable;
use Proxyma\Application\Execution\DestinationPolicy;
use Proxyma\Application\Execution\ProxyResolver;
use Proxyma\Contracts\Catalog\CatalogQueries;
use Proxyma\Domain\Catalog\CredentialRef;
use Proxyma\Domain\Catalog\Endpoint;
use Proxyma\Domain\Catalog\ProxyProtocol;
use Proxyma\Domain\Catalog\UpsertTargetCommand;
use Proxyma\Domain\Execution\ExecutionContext;
use Proxyma\Domain\Execution\OperationCorrelation;
use Proxyma\Domain\Execution\OwnerRef;
use Proxyma\Domain\Execution\PreparedHeaders;
use Proxyma\Domain\Execution\RequestTimeoutPolicy;
use Proxyma\Domain\Execution\ResolvedSecret;
use Proxyma\Domain\Execution\RouteSelection;
use Proxyma\Domain\Execution\SafeUrl;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireBody;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Infrastructure\Http\DnsResolver;
use Proxyma\Infrastructure\Http\PhysicalHttpTransport;
use Proxyma\Infrastructure\Http\TlsTrust;
use ReflectionClass;
use Tests\Support\WireServer;

trait PhysicalFixtures
{
    protected function initializePhysicalFixture(): void
    {
        $this->initializeQuotaFixture();
        $this->socket = new WireServer;
        $this->transport = $this->app->make(PhysicalHttpTransport::class);
        $this->socketWorkers = [];
    }

    protected function physicalPrepared(string $path = '/ok', string $listener = 'http', ?string $proxyListener = null,
        bool $trustOrigin = true, bool $trustProxy = true, ?string $password = null, ?RequestTimeoutPolicy $timeouts = null,
        ?string $originBundle = null, bool $pinProxy = true, int $proxyPortOffset = 0): array
    {
        $scope = $this->transactions->scope;
        $url = new SafeUrl($this->socket->url($path, $listener));
        $targetId = Uuid::random();
        $target = $this->commands()->upsertTarget(new UpsertTargetCommand($targetId, $this->tenant,
            'wire-'.str_replace('-', '', $targetId->value), $url->origin, 'default', $this->actor()));
        $this->catalog['target'] = $targetId;
        $proxy = null;
        $credential = null;
        $dns = new DnsResolver($scope, static fn (): array => ['127.0.0.1']);
        if ($proxyListener !== null) {
            $protocol = match ($proxyListener) {
                'socks' => ProxyProtocol::Socks5,
                'proxy_tls', 'proxy_wrong', 'proxy_expired' => ProxyProtocol::Https,
                default => ProxyProtocol::Http,
            };
            $endpointId = Uuid::random();
            $ref = $proxyListener === 'auth' ? new CredentialRef('fixture:proxy', 1) : null;
            $endpoint = new Endpoint($endpointId, $this->tenant, $protocol,
                $proxyListener === 'proxy_expired' ? 'origin.test' : 'proxy.test', $this->socket->ports[$proxyListener], $ref);
            $this->table('endpoints')->insert(['id' => $endpointId->value, 'tenant_key' => $this->tenant->value,
                'protocol' => $protocol->value, 'host' => $endpoint->host, 'port' => $endpoint->port,
                'credential_ref' => $ref?->reference, 'credential_version' => $ref?->version]);
            $this->table('routes')->where('id', $this->catalog['route']->value)->update(['kind' => 'proxy', 'endpoint_id' => $endpointId->value, 'revision' => 2]);
            if ($proxyPortOffset !== 0) {
                $endpoint = new Endpoint($endpointId, $this->tenant, $protocol, $endpoint->host, $endpoint->port + $proxyPortOffset, $ref);
            }
            $proxy = new ProxyResolver($dns)->resolve($endpoint, 500);
            $credential = $ref === null ? null : new ResolvedSecret($ref, 'user@/%', $password ?? 'p:a@ss/%?');
        }
        \selectionHealth($this->db, $this->tenant);
        $route = $this->app->make(CatalogQueries::class)->findRoute($this->tenant, $this->catalog['route']);
        foreach ([$url->origin->budgetScopeKey(), $url->origin->routeScopeKey($route->id), $url->origin->egressScopeKey($route->egress->key)] as $key) {
            if (! $this->table('budgets')->where('scope_key', $key)->exists()) {
                $this->budget($key);
            }
        }
        $this->table('budgets')->update(['burst' => 100, 'max_inflight' => 100]);
        $this->originBudget = new Uuid($this->table('budgets')->where('scope_key', $url->origin->budgetScopeKey())->value('id'));
        $this->quota->configure($this->tenant, $this->originBudget, 20, 40, 1);
        $id = Uuid::random();
        $timeouts ??= new RequestTimeoutPolicy(500, 500, 1500, 0, maxAttempts: 3, maxDistinctRoutes: 3);
        $operation = new OperationCorrelation($id, new OwnerRef($this->tenant, 'standalone', $id->value, 'fixture-token'), new DateTimeImmutable('+1 minute'), 'wire:'.$id->value);
        $this->operations->create($operation, $this->catalog['profile'], $targetId, $timeouts);
        $request = new WireRequest($id, Uuid::random(), $targetId, 1, 'POST', $url, $timeouts, $operation->deadlineAt,
            new PreparedHeaders(['Authorization' => 'Bearer fixture-provider-secret', 'X-Fixture' => 'sealed']), WireBody::fromString('sealed-body'));
        $policy = new DestinationPolicy($dns, [$targetId->value => ['origin.test' => ['127.0.0.1']]], [$url->origin->port]);
        $context = new ReflectionClass(ExecutionContext::class)->newInstanceWithoutConstructor();
        $destination = $policy->resolve($url, $context, 500, $target);
        $trust = new TlsTrust($scope, $trustOrigin ? ['origin.test' => $originBundle ?? $this->socket->certificates.'/ca.pem'] : [],
            $trustProxy ? ['proxy.test' => $this->socket->certificates.'/ca.pem', 'origin.test' => $this->socket->certificates.'/ca.pem'] : []);
        $wire = $this->sealer->seal($request, $route, $destination, $trust->tlsConfigurationFor($url->origin, $targetId), $credential,
            $proxy === null ? null : $trust->proxyConfigurationFor($proxy->endpoint->host), $pinProxy ? $proxy : null);
        $selection = new RouteSelection($route->id, $route->kind, $this->catalog['profile'], $route->endpointId, $route->credentialVersion, $route->egress);
        $input = $this->reservations->prepare($wire->request, $operation, $selection, \selectionPolicy($this->tenant, $this->catalog['profile'], $targetId), $this->work);
        $ticket = $this->reservations->enqueue($input, $this->work);
        $permit = $this->reservations->reserve($ticket, $input);
        $this->claims->prepare($permit, $input, $wire);

        return [$permit, $input, $wire];
    }

    protected function physicalCapability(array $prepared): mixed
    {
        return $this->claims->activate($this->claims->claim(...$prepared));
    }
}
