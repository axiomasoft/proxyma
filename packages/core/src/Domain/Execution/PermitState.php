<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;

enum PermitState: string
{
    case Reserved = 'reserved';
    case Authorized = 'authorized';
    case Completed = 'completed';
    case UnknownAfterAuthorization = 'unknown_after_authorization';
    case CancelledBeforeSend = 'cancelled_before_send';
    case ExpiredBeforeSend = 'expired_before_send';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Reserved => in_array($next, [self::Authorized, self::CancelledBeforeSend, self::ExpiredBeforeSend], true),
            self::Authorized => in_array($next, [self::Completed, self::UnknownAfterAuthorization], true),
            self::UnknownAfterAuthorization => $next === self::Completed,
            self::Completed, self::CancelledBeforeSend, self::ExpiredBeforeSend => false,
        };
    }

    public function transitionTo(self $next): self
    {
        if (! $this->canTransitionTo($next)) {
            throw new InvalidArgumentException("Illegal permit state transition from '{$this->value}' to '{$next->value}'.");
        }

        return $next;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::CancelledBeforeSend, self::ExpiredBeforeSend], true);
    }

    public function consumesQuotaPermanently(): bool
    {
        return in_array($this, [self::Authorized, self::Completed, self::UnknownAfterAuthorization], true);
    }

    public function holdsInflightCapacity(): bool
    {
        return in_array($this, [self::Reserved, self::Authorized], true);
    }

    /**
     * Maps legacy Agelto permit states ('reserved', 'sent', 'released', 'expired') using actual
     * durable authorization and receipt facts rather than renaming 'released' blindly to 'completed'.
     */
    public static function fromLegacyStatus(string $legacyState, bool $wasAuthorized, ?bool $hasVerifiedReceipt = null): self
    {
        return match ($legacyState) {
            'reserved' => $wasAuthorized
                ? throw new InvalidArgumentException('Permit with durable authorization cannot remain in reserved state.')
                : self::Reserved,
            'sent' => $wasAuthorized
                ? self::Authorized
                : throw new InvalidArgumentException('Legacy sent state requires a durable authorization timestamp.'),
            'released' => ! $wasAuthorized
                ? self::CancelledBeforeSend
                : ($hasVerifiedReceipt === true ? self::Completed : self::UnknownAfterAuthorization),
            'expired' => ! $wasAuthorized
                ? self::ExpiredBeforeSend
                : ($hasVerifiedReceipt === true ? self::Completed : self::UnknownAfterAuthorization),
            default => throw new InvalidArgumentException("Unsupported legacy permit state '{$legacyState}'."),
        };
    }
}
