<?php

declare(strict_types=1);

namespace Proxyma\Tests\Support;

use Closure;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Contracts\Authorization\Authorizer;
use Proxyma\Contracts\Authorization\TenantContextResolver;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantContext;
use Proxyma\Domain\Policy\TenantKey;

trait TenantFixtures
{
    protected function useTenant(TenantKey $tenant, ?ActorRef $actor = null): void
    {
        $this->app->instance(TenantContextResolver::class, new class(new TenantContext($tenant, $actor ?? new ActorRef('operator', 'fixture-operator'))) implements TenantContextResolver
        {
            public function __construct(private TenantContext $context) {}

            public function resolve(): TenantContext
            {
                return $this->context;
            }
        });
    }

    /** @param Closure(ActorRef, TenantKey, string, Uuid): bool $allows */
    protected function permissions(Closure $allows): void
    {
        $this->app->instance(Authorizer::class, new class($allows) implements Authorizer
        {
            public function __construct(private Closure $allows) {}

            public function authorize(ActorRef $actor, TenantKey $tenant, string $ability, Uuid $resourceId): void
            {
                if (! ($this->allows)($actor, $tenant, $ability, $resourceId)) {
                    throw new AuthorizationDenied;
                }
            }
        });
    }
}
