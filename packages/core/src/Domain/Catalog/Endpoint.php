<?php

declare(strict_types=1);

namespace Proxyma\Domain\Catalog;

use DateTimeImmutable;
use InvalidArgumentException;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;

final readonly class Endpoint
{
    public string $host;

    public function __construct(
        public Uuid $id,
        public TenantKey $tenantKey,
        public ProxyProtocol $protocol,
        string $host,
        public int $port,
        public ?CredentialRef $credential = null,
        public int $revision = 1,
        public ?DateTimeImmutable $retiredAt = null,
    ) {
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Endpoint port must be between 1 and 65535.');
        }

        if ($revision < 1) {
            throw new InvalidArgumentException('Endpoint revision must be at least 1.');
        }

        $this->host = CanonicalOrigin::normalizeHost($host);
    }

    public function credentialVersion(): int
    {
        return $this->credential->version ?? 1;
    }

    public function isRetired(): bool
    {
        return $this->retiredAt !== null;
    }
}
