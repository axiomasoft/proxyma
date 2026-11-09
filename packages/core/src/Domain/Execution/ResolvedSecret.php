<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;
use LogicException;
use Proxyma\Domain\Catalog\CredentialRef;

/**
 * Runtime-only resolved secret material for transport binding; never serialized or logged.
 */
final readonly class ResolvedSecret
{
    public function __construct(
        public CredentialRef $reference,
        public ?string $username,
        private string $secretValue,
    ) {
        if ($secretValue === '' || preg_match('/[\r\n\x00]/', $secretValue) === 1) {
            throw new InvalidArgumentException('Resolved secret value must be non-empty and free of control characters.');
        }

        if ($username !== null && ($username === '' || preg_match('/[\r\n\x00:]/', $username) === 1)) {
            throw new InvalidArgumentException('Resolved username must not be empty or contain colons/control characters.');
        }
    }

    public function revealSecret(): string
    {
        return $this->secretValue;
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('ResolvedSecret cannot be serialized.');
    }

    /**
     * @return array{reference: string, version: int, has_username: bool}
     */
    public function __debugInfo(): array
    {
        return [
            'reference' => $this->reference->reference,
            'version' => $this->reference->version,
            'has_username' => $this->username !== null,
        ];
    }
}
