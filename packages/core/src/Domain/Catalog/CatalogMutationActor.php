<?php

declare(strict_types=1);

namespace Proxyma\Domain\Catalog;

use InvalidArgumentException;

final readonly class CatalogMutationActor
{
    public string $actorId;

    public string $reason;

    public function __construct(
        string $actorId,
        string $reason,
        public ?int $expectedRevision = null,
    ) {
        $actorId = trim($actorId);
        $reason = trim($reason);

        if ($actorId === '' || strlen($actorId) > 128 || preg_match('/[\x00-\x1f\x7f]/', $actorId) === 1) {
            throw new InvalidArgumentException('Catalog mutation actorId must be non-empty and free of control characters.');
        }

        if ($reason === '' || strlen($reason) > 512 || preg_match('/[\x00-\x1f\x7f]/', $reason) === 1) {
            throw new InvalidArgumentException('Catalog mutation reason must be non-empty and free of control characters.');
        }

        if ($expectedRevision !== null && $expectedRevision < 0) {
            throw new InvalidArgumentException('Expected revision must be non-negative.');
        }

        $this->actorId = $actorId;
        $this->reason = $reason;
    }
}
