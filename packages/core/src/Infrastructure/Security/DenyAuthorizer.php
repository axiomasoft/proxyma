<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Security;

use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Contracts\Authorizer;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;

final class DenyAuthorizer implements Authorizer
{
    public function authorize(ActorRef $actor, TenantKey $tenant, string $ability, Uuid $resourceId): void
    {
        throw new AuthorizationDenied;
    }
}
