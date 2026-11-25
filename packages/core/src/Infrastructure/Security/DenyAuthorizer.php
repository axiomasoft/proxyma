<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Security;

use Packman\Attributes\Api;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Contracts\Authorization\Authorizer;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;

#[Api(since: '1.0')]
final class DenyAuthorizer implements Authorizer
{
    public function authorize(ActorRef $actor, TenantKey $tenant, string $ability, Uuid $resourceId): void
    {
        throw new AuthorizationDenied;
    }
}
