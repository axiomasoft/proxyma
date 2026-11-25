<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Security;

use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Internal;
use Proxyma\Contracts\Authorization\Authorizer;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;

/** @internal Повтор команды проверяет актуальный adapter, а не прежний scoped instance. */
#[Internal]
final readonly class ContainerAuthorizer implements Authorizer
{
    public function __construct(private Container $container) {}

    public function authorize(ActorRef $actor, TenantKey $tenant, string $ability, Uuid $resourceId): void
    {
        $this->container->make(Authorizer::class)->authorize($actor, $tenant, $ability, $resourceId);
    }
}
