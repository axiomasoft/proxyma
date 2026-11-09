<?php

declare(strict_types=1);

namespace Proxyma\Domain\Catalog;

use InvalidArgumentException;

final readonly class CatalogMutationActor
{
    public string $actorId;

    public string $reason;

    public ActorRef $actor;

    public function __construct(
        string|ActorRef $actorId,
        string $reason,
        public ?int $expectedRevision = null,
        public ?string $idempotencyKey = null,
    ) {
        $actor = $actorId instanceof ActorRef ? $actorId : new ActorRef('operator', trim($actorId));
        $actorId = $actor->id;
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

        if ($idempotencyKey !== null && preg_match('/^[\x21-\x7e]{1,128}$/D', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('Idempotency key must contain 1..128 visible ASCII bytes.');
        }

        $this->actor = $actor;
        $this->actorId = $actorId;
        $this->reason = $reason;
    }
}
