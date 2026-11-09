<?php

declare(strict_types=1);

namespace Proxyma\Application\Authorization;

use Proxyma\Contracts\Authorizer;
use Proxyma\Contracts\TenantContextResolver;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantContext;
use Proxyma\Domain\Policy\TenantKey;

final readonly class CatalogAccess
{
    public function __construct(private TenantContextResolver $resolver, private Authorizer $authorizer) {}

    public function authorize(TenantKey $tenant, string $ability, Uuid $id, ?ActorRef $actor = null): TenantContext
    {
        $context = $this->resolver->resolve();
        if (! $context->tenant->equals($tenant)
            || ($actor !== null && ($actor->type !== $context->actor->type || $actor->id !== $context->actor->id))) {
            throw new AuthorizationDenied;
        }
        $this->authorizer->authorize($context->actor, $context->tenant, $ability, $id);

        return $context;
    }
}
