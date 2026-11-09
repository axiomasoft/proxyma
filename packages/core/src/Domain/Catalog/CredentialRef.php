<?php

declare(strict_types=1);

namespace Proxyma\Domain\Catalog;

use InvalidArgumentException;

final readonly class CredentialRef
{
    public string $reference;

    public int $version;

    public function __construct(string $reference, int $version = 1)
    {
        if ($reference === '' || strlen($reference) > 255 || preg_match('/[\x00-\x20\x7f]/', $reference) === 1) {
            throw new InvalidArgumentException('Credential reference must be a non-empty opaque key without whitespace or control characters.');
        }

        if ($version < 1) {
            throw new InvalidArgumentException('Credential version must be at least 1.');
        }

        $this->reference = $reference;
        $this->version = $version;
    }

    public function equals(self $other): bool
    {
        return $this->reference === $other->reference && $this->version === $other->version;
    }
}
