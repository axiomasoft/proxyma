<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use Packman\Attributes\Api;
use Proxyma\Domain\Execution\Uuid;

#[Api(since: '1.0')]
enum PolicyScope: string
{
    case Global = 'global';
    case Tenant = 'tenant';
    case Panel = 'panel';
    case Entity = 'entity';

    public function authorizationId(TenantKey $tenant, string $scopeId): Uuid
    {
        $digest = hash('sha256', json_encode([$tenant->value, $this->value, $scopeId], JSON_THROW_ON_ERROR));

        return new Uuid(sprintf('%s-%s-%s-%s-%s', substr($digest, 0, 8), substr($digest, 8, 4), substr($digest, 12, 4), substr($digest, 16, 4), substr($digest, 20, 12)));
    }
}
