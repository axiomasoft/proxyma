<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Proxyma\Application\Execution\DestinationPolicy;
use Proxyma\Application\Execution\WireSealer;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\EgressIdentity;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Catalog\Target;
use Proxyma\Domain\Execution\ExecutionContext;
use Proxyma\Domain\Execution\RequestTimeoutPolicy;
use Proxyma\Domain\Execution\ResolvedUrl;
use Proxyma\Domain\Execution\SafeUrl;
use Proxyma\Domain\Execution\SealedWire;
use Proxyma\Domain\Execution\TlsTrustConfiguration;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Http\DnsResolver;

function destinationFixture(Closure $resolver, array $allow = [], array $ports = [80, 443]): array
{
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('inTransaction')->andReturn(false);
    $connection = new Connection($pdo);
    $scope = new ConnectionScope($connection, new DatabaseTransactionsManager);
    $target = new Target(new Uuid('00000000-0000-0000-0000-000000000001'), new TenantKey('fixture'), 'fixture', CanonicalOrigin::of('https', 'example.test'), 'fixture', 3);
    $context = new ReflectionClass(ExecutionContext::class)->newInstanceWithoutConstructor();

    return [new DestinationPolicy(new DnsResolver($scope, $resolver), $allow, $ports), $target, $context];
}

it('закрепляет публичные адреса и пересчитывает DNS при редиректе', function (): void {
    $responses = [['8.8.8.8', '2606:4700:4700::1111'], ['127.0.0.1']];
    [$policy, $target, $context] = destinationFixture(function () use (&$responses): ?array {
        return array_shift($responses);
    });
    $result = $policy->resolve(new SafeUrl('HTTPS://EXAMPLE.TEST:443'), $context, 100, $target);
    expect($result->url->reveal())->toBe('https://example.test/')->and($result->addresses)->toHaveCount(2)
        ->and($result->originalHost)->toBe('example.test')->and($result->targetRevision)->toBe(3);
    expect(fn () => $policy->resolveRedirect('https://example.test/next', $context, 100, $target))->toThrow(InvalidArgumentException::class);
});

it('отклоняет любой запрещённый адрес в DNS ответе', function (string $address): void {
    [$policy, $target, $context] = destinationFixture(fn (): array => ['8.8.8.8', $address]);
    expect(fn () => $policy->resolve(new SafeUrl('https://example.test/'), $context, 100, $target))->toThrow(InvalidArgumentException::class);
})->with(['127.0.0.1', '10.0.0.1', '100.64.0.1', '169.254.169.254', '::ffff:127.0.0.1', '::1', 'fe80::1', 'fc00::1']);

it('разрешает только точную fixture привязку target и адреса', function (): void {
    $id = '00000000-0000-0000-0000-000000000001';
    [$policy, $target, $context] = destinationFixture(fn (): array => ['127.0.0.1', '8.8.8.8'], [$id => ['example.test' => ['127.0.0.1']]]);
    expect($policy->resolve(new SafeUrl('https://example.test/'), $context, 100, $target)->address)->toBe('127.0.0.1');
    expect(fn () => $policy->resolve(new SafeUrl('https://other.test/'), $context, 100, $target))->toThrow(InvalidArgumentException::class);
});

it('отклоняет неоднозначные URL', function (string $url): void {
    expect(fn (): SafeUrl => new SafeUrl($url))->toThrow(InvalidArgumentException::class);
})->with(['https://user@example.test/', 'https://example.test/#x', 'ftp://example.test/', 'http://127.1/', 'https://example.test/%xx', 'https://example.test:99999/']);

it('нормализует IDNA и проверяет разрешённый порт', function (): void {
    expect((new SafeUrl('https://bücher.example/'))->origin->host)->toBe('xn--bcher-kva.example');
    [$policy, $target, $context] = destinationFixture(fn (): array => ['8.8.8.8']);
    $portTarget = new Target($target->id, $target->tenantKey, 'fixture', CanonicalOrigin::of('https', 'example.test', 8443), 'fixture');
    expect(fn () => $policy->resolve(new SafeUrl('https://example.test:8443/'), $context, 100, $portTarget))->toThrow(InvalidArgumentException::class);
    [$customPolicy] = destinationFixture(fn (): array => ['8.8.8.8'], ports: [8443]);
    expect($customPolicy->resolve(new SafeUrl('https://example.test:8443/'), $context, 100, $portTarget)->port)->toBe(8443);
});

it('принимает только проверенный descriptor для direct и proxy', function (string $scheme, RouteKind $kind): void {
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('inTransaction')->andReturn(false);
    $scope = new ConnectionScope(new Connection($pdo), new DatabaseTransactionsManager);
    $tenant = new TenantKey('fixture');
    $targetId = Uuid::random();
    $url = new SafeUrl($scheme.'://example.test/');
    $target = new Target($targetId, $tenant, 'fixture', $url->origin, 'fixture');
    $context = new ReflectionClass(ExecutionContext::class)->newInstanceWithoutConstructor();
    $policy = new DestinationPolicy(new DnsResolver($scope, fn (): array => ['8.8.8.8']));
    $wire = new WireRequest(Uuid::random(), Uuid::random(), $targetId, 1, 'GET', $url, new RequestTimeoutPolicy(1, 1, 1), new DateTimeImmutable('+1 hour'));
    $route = new Route(Uuid::random(), $tenant, 'route', $kind, $kind === RouteKind::Proxy ? Uuid::random() : null, true, EgressIdentity::explicit('nat:fixture'));
    $sealer = new WireSealer($scope, str_repeat('x', 32));
    expect(fn (): ResolvedUrl => new ResolvedUrl($url, '127.0.0.1', policyVerified: true))->toThrow(InvalidArgumentException::class);
    expect(fn (): SealedWire => $sealer->seal($wire, $route, new ResolvedUrl($url, '127.0.0.1'), new TlsTrustConfiguration))->toThrow(AdmissionRejected::class);
    $sealed = $sealer->seal($wire, $route, $policy->resolve($url, $context, 100, $target), new TlsTrustConfiguration);
    expect($sealed->destination->address)->toBe('8.8.8.8');
})->with([['http', RouteKind::Direct], ['https', RouteKind::Direct], ['http', RouteKind::Proxy], ['https', RouteKind::Proxy]]);
