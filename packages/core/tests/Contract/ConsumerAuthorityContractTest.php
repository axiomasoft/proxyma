<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Application\Policy\SettingRegistry;
use Proxyma\Contracts\Admission\LockedAdmissionGuard;
use Proxyma\Contracts\Admission\SqlConnectionParticipant;
use Proxyma\Domain\Execution\AdmissionLockTier;
use Proxyma\Domain\Execution\ClaimDenied;
use Proxyma\Domain\Execution\LockedOwnerState;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Panels\DefinitionError;
use Proxyma\Domain\Panels\ExtensionDefinition;
use Proxyma\Domain\Panels\ExtensionKind;

test('consumer guard не может объявить ранний owner tier вместо network adapter tier', function (AdmissionLockTier $tier): void {
    $guard = new class($tier) implements LockedAdmissionGuard, SqlConnectionParticipant
    {
        public function __construct(private AdmissionLockTier $declaredTier) {}

        public function connection(): Connection
        {
            throw new LogicException('Boot validation cannot open SQL connections.');
        }

        public function tier(): AdmissionLockTier
        {
            return $this->declaredTier;
        }

        public function evaluateLocked(WireRequest $request, LockedOwnerState $owner, LockedTransactionContext $transaction): ?ClaimDenied
        {
            throw new LogicException('Boot validation cannot evaluate admission.');
        }
    };
    $registry = new DefinitionRegistry(new SettingRegistry([]));
    $registry->registerExtension(new ExtensionDefinition('consumer.adapter', ExtensionKind::LockedAdmissionGuard, $guard, tier: $tier));
    if ($tier !== AdmissionLockTier::AdapterGuard) {
        expect(fn (): string => $registry->freeze())->toThrow(DefinitionError::class);

        return;
    }
    expect($registry->freeze())->toHaveLength(64);
    expect(fn () => $registry->registerExtension(new ExtensionDefinition('late.guard', ExtensionKind::LockedAdmissionGuard, $guard, tier: $tier)))
        ->toThrow(DefinitionError::class);
})->with(AdmissionLockTier::cases());
