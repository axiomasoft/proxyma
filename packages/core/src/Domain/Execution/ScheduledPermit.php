<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ScheduledPermit
{
    public DateTimeImmutable $slotAt;

    public DateTimeImmutable $expiresAt;

    public ?DateTimeImmutable $authorizedAt;

    public function __construct(
        public Uuid $permitId,
        public DemandTicket $ticket,
        DateTimeImmutable $slotAt,
        DateTimeImmutable $expiresAt,
        public PermitEvidence $evidence,
        public PermitState $state = PermitState::Reserved,
        ?DateTimeImmutable $authorizedAt = null,
    ) {
        $utc = new DateTimeZone('UTC');
        $normalizedSlot = $slotAt->setTimezone($utc);
        $normalizedExpires = $expiresAt->setTimezone($utc);
        $normalizedAuthorized = $authorizedAt?->setTimezone($utc);

        if ($normalizedExpires <= $normalizedSlot) {
            throw new InvalidArgumentException('Permit expiresAt must be strictly greater than slotAt.');
        }

        if ($state === PermitState::Reserved && $normalizedAuthorized !== null) {
            throw new InvalidArgumentException('Reserved permit must not have an authorizedAt timestamp.');
        }

        if ($state->consumesQuotaPermanently() && $normalizedAuthorized === null) {
            throw new InvalidArgumentException("Permit in state '{$state->value}' requires an authorizedAt timestamp.");
        }

        $this->slotAt = $normalizedSlot;
        $this->expiresAt = $normalizedExpires;
        $this->authorizedAt = $normalizedAuthorized;
    }
}
