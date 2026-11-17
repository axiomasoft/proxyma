<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use Packman\Attributes\Api;
use Proxyma\Domain\Catalog\CredentialRef;
use SensitiveParameter;

/**
 * Runtime-only resolved secret material for transport binding; never serialized or logged.
 */
#[Api(since: '1.0')]
final readonly class ResolvedSecret implements JsonSerializable
{
    public function __construct(
        public CredentialRef $reference,
        #[SensitiveParameter] public ?string $username,
        #[SensitiveParameter] private string $secretValue,
    ) {
        if ($secretValue === '' || preg_match('//u', $secretValue) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $secretValue) === 1) {
            throw new InvalidArgumentException('Resolved secret value must be non-empty and free of control characters.');
        }

        if ($username !== null && ($username === '' || preg_match('//u', $username) !== 1 || preg_match('/[\x00-\x1f\x7f:]/', $username) === 1)) {
            throw new InvalidArgumentException('Resolved username must not be empty or contain colons/control characters.');
        }
    }

    public function revealSecret(): string
    {
        return $this->secretValue;
    }

    public function encodedUserInfo(): string
    {
        return rawurlencode($this->username ?? '').':'.rawurlencode($this->secretValue);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('ResolvedSecret cannot be reconstructed.');
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
