<?php

declare(strict_types=1);

namespace Proxyma\Application\Authorization;

use Packman\Attributes\Api;
use Proxyma\Contracts\Authorization\Authorizer;
use Proxyma\Contracts\Authorization\TenantContextResolver;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantContext;
use Proxyma\Domain\Policy\TenantKey;

#[Api(since: '1.0')]
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
