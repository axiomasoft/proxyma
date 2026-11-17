<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;
use LogicException;
use Packman\Attributes\Api;
use Proxyma\Domain\Policy\TenantKey;

/**
 * External execution owner claim (opaque id, token, generation).
 * Distinct from SubjectRef (entity setting binding is not execution ownership).
 */
#[Api(since: '1.0')]
final readonly class OwnerRef
{
    public string $ownerType;

    public string $ownerId;

    public function __construct(
        public TenantKey $tenantKey,
        string $ownerType,
        string $ownerId,
        private string $ownerToken,
        public int $generation = 1,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $ownerType) !== 1) {
            throw new InvalidArgumentException('Owner type must be a valid canonical identifier.');
        }

        if ($ownerId === '' || strlen($ownerId) > 255 || preg_match('/[\x00-\x20\x7f]/', $ownerId) === 1) {
            throw new InvalidArgumentException('Owner opaque id must be non-empty and free of whitespace or control bytes.');
        }

        if ($ownerToken === '' || strlen($ownerToken) > 255 || preg_match('/[\x00-\x20\x7f]/', $ownerToken) === 1) {
            throw new InvalidArgumentException('Owner token must be non-empty and free of whitespace or control bytes.');
        }

        if ($generation < 1) {
            throw new InvalidArgumentException('Owner generation must be at least 1.');
        }

        $this->ownerType = $ownerType;
        $this->ownerId = $ownerId;
    }

    public function token(): string
    {
        return $this->ownerToken;
    }

    public function matchesIdentityAndToken(self $other): bool
    {
        return $this->tenantKey->equals($other->tenantKey)
            && $this->ownerType === $other->ownerType
            && $this->ownerId === $other->ownerId
            && $this->generation === $other->generation
            && hash_equals($this->ownerToken, $other->ownerToken);
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('OwnerRef cannot be serialized because it holds a live execution lease token.');
    }

    /**
     * @return array{tenant: string, owner_type: string, owner_id: string, generation: int}
     */
    public function __debugInfo(): array
    {
        return [
            'tenant' => $this->tenantKey->value,
            'owner_type' => $this->ownerType,
            'owner_id' => $this->ownerId,
            'generation' => $this->generation,
        ];
    }
}
