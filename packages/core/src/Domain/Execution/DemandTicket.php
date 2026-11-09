<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class DemandTicket
{
    public DateTimeImmutable $expiresAt;

    public DateTimeImmutable $operationDeadlineAt;

    public function __construct(
        public Uuid $demandId,
        public Uuid $operationId,
        public Uuid $physicalAttemptId,
        public int $ticketSequence,
        public string $limitingScopeKey,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $operationDeadlineAt,
        public DemandState $state = DemandState::Active,
    ) {
        if ($ticketSequence < 1) {
            throw new InvalidArgumentException('Demand FIFO ticket sequence must be at least 1.');
        }

        if ($limitingScopeKey === '' || strlen($limitingScopeKey) > 255 || preg_match('/[\x00-\x20\x7f]/', $limitingScopeKey) === 1) {
            throw new InvalidArgumentException('Limiting scope key must be a non-empty canonical key.');
        }

        $utc = new DateTimeZone('UTC');
        $normalizedExpires = $expiresAt->setTimezone($utc);
        $normalizedDeadline = $operationDeadlineAt->setTimezone($utc);

        if ($normalizedExpires > $normalizedDeadline) {
            throw new InvalidArgumentException('Demand ticket expiration cannot exceed the bounded operation deadline.');
        }

        $this->expiresAt = $normalizedExpires;
        $this->operationDeadlineAt = $normalizedDeadline;
    }

    /**
     * Fair FIFO tie-break: sequence number first, then canonical UUID.
     */
    public function comparePriority(self $other): int
    {
        if ($this->limitingScopeKey !== $other->limitingScopeKey) {
            throw new InvalidArgumentException('Cannot compare FIFO priority across different limiting scopes.');
        }

        return ($this->ticketSequence <=> $other->ticketSequence)
            ?: ($this->demandId->value <=> $other->demandId->value);
    }

    public function withState(DemandState $nextState): self
    {
        return new self(
            demandId: $this->demandId,
            operationId: $this->operationId,
            physicalAttemptId: $this->physicalAttemptId,
            ticketSequence: $this->ticketSequence,
            limitingScopeKey: $this->limitingScopeKey,
            expiresAt: $this->expiresAt,
            operationDeadlineAt: $this->operationDeadlineAt,
            state: $this->state->transitionTo($nextState),
        );
    }
}
