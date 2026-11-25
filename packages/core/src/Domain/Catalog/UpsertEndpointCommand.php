<?php

declare(strict_types=1);

namespace Proxyma\Domain\Catalog;

use Packman\Attributes\Api;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;

#[Api(since: '1.0')]
final readonly class UpsertEndpointCommand
{
    public function __construct(
        public Uuid $endpointId,
        public TenantKey $tenantKey,
        public ProxyProtocol $protocol,
        public string $host,
        public int $port,
        public ?CredentialRef $credential,
        public CatalogMutationActor $actor,
    ) {}
}
