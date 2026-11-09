<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use Proxyma\Domain\Health\HealthEpochMap;

/**
 * Pure domain transition rules for late receipts:
 * - unknown_after_authorization -> completed is allowed for matching attempt + wire fingerprint and first verified receipt.
 * - Already released lease/inflight stays released; consumed quota never changes.
 * - Duplicate receipt with same receiptId and receiptDigest is idempotent; differing digest or receiptId is a conflict.
 * - Stale health epoch forbids health reducer update, but does not prevent receipt persistence or permit completion.
 */
final readonly class LateReceiptTransition
{
    public static function evaluate(
        PermitState $currentState,
        Uuid $expectedAttemptId,
        ContentHash $expectedWireFingerprint,
        LateReceiptClaim $incoming,
        HealthEpochMap $liveHealthEpochs,
        ?string $existingReceiptId = null,
        ?ContentHash $existingReceiptDigest = null,
    ): LateReceiptResult {
        if (! $expectedAttemptId->equals($incoming->physicalAttemptId) || ! $expectedWireFingerprint->equals($incoming->wireFingerprint)) {
            return new LateReceiptResult(
                outcome: LateReceiptOutcome::ConflictingReceipt,
                permitState: $currentState,
                receiptPersisted: false,
                healthReducerAllowed: false,
            );
        }

        $epochStale = $incoming->authorizationHealthEpochs->isStaleComparedTo($liveHealthEpochs);

        if ($currentState === PermitState::Completed && $existingReceiptId !== null && $existingReceiptDigest !== null) {
            if ($existingReceiptId === $incoming->receiptId && $existingReceiptDigest->equals($incoming->receiptDigest)) {
                return new LateReceiptResult(
                    outcome: LateReceiptOutcome::IdempotentDuplicate,
                    permitState: PermitState::Completed,
                    receiptPersisted: true,
                    healthReducerAllowed: false,
                );
            }

            return new LateReceiptResult(
                outcome: LateReceiptOutcome::ConflictingReceipt,
                permitState: PermitState::Completed,
                receiptPersisted: false,
                healthReducerAllowed: false,
            );
        }

        if ($currentState !== PermitState::UnknownAfterAuthorization) {
            return new LateReceiptResult(
                outcome: LateReceiptOutcome::RejectedInvalidState,
                permitState: $currentState,
                receiptPersisted: false,
                healthReducerAllowed: false,
            );
        }

        return new LateReceiptResult(
            outcome: LateReceiptOutcome::TransitionedToCompleted,
            permitState: $currentState->transitionTo(PermitState::Completed),
            receiptPersisted: true,
            healthReducerAllowed: ! $epochStale,
        );
    }
}
