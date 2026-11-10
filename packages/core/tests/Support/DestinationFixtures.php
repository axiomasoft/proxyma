<?php

declare(strict_types=1);

namespace Proxyma\Tests\Support;

use Proxyma\Application\Execution\DestinationPolicy;
use Proxyma\Domain\Catalog\Target;
use Proxyma\Domain\Execution\ExecutionContext;
use Proxyma\Domain\Execution\ResolvedUrl;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Http\DnsResolver;
use ReflectionClass;

function fixtureDestination(ConnectionScope $scope, WireRequest $request): ResolvedUrl
{
    $target = new Target($request->targetId, new TenantKey('fixture'), 'fixture', $request->url->origin, 'fixture');
    $context = (new ReflectionClass(ExecutionContext::class))->newInstanceWithoutConstructor();
    $policy = new DestinationPolicy(new DnsResolver($scope, static fn () => ['8.8.8.8']));

    return $policy->resolve($request->url, $context, 100, $target);
}
