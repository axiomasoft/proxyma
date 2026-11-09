<?php

declare(strict_types=1);

use Proxyma\Contracts\TransactionCoordinator;
use Proxyma\Domain\Execution\PermitState;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Domain\Policy\TenantKey;
use Tests\TestCase;

uses(TestCase::class);

it('wires ProxymaServiceProvider config and TransactionCoordinator binding', function (): void {
    expect(config('proxyma.default_tenant'))->toBe(TenantKey::SYSTEM)
        ->and(config('proxyma.safety.admission'))->toBe('mandatory')
        ->and(app()->bound(TransactionCoordinator::class))->toBeTrue();

    $coordinator = app(TransactionCoordinator::class);
    expect($coordinator->connectionName())->toBe('pgsql')
        ->and($coordinator->inTransaction())->toBeFalse();
});

it('keeps domain value objects and permit legacy status mapping deterministic', function (): void {
    $tenant = TenantKey::system();
    $subject = new SubjectRef($tenant, 'crawler', '42');

    expect($subject->key())->toBe('system:crawler:42')
        ->and(Uuid::random()->value)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D')
        ->and(PermitState::fromLegacyStatus('released', wasAuthorized: false))->toBe(PermitState::CancelledBeforeSend)
        ->and(PermitState::fromLegacyStatus('released', wasAuthorized: true, hasVerifiedReceipt: false))->toBe(PermitState::UnknownAfterAuthorization)
        ->and(PermitState::fromLegacyStatus('released', wasAuthorized: true, hasVerifiedReceipt: true))->toBe(PermitState::Completed);
});
