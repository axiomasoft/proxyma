<?php

declare(strict_types=1);

use Proxyma\Application\Admission\ClaimSend;
use Proxyma\Application\Catalog\CatalogConflict;
use Proxyma\Application\Catalog\CatalogInvalidMutation;
use Proxyma\Application\Catalog\RotateCredentials;
use Proxyma\Application\Execution\OutcomePolicyRegistry;
use Proxyma\Application\Health\ResetHealth;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\CredentialRef;
use Proxyma\Domain\Catalog\EgressIdentity;
use Proxyma\Domain\Catalog\ProxyProtocol;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Catalog\UpsertEndpointCommand;
use Proxyma\Domain\Catalog\UpsertRouteCommand;
use Proxyma\Domain\Execution\DemandPurpose;
use Proxyma\Domain\Execution\LateReceiptClaim;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\HealthScope;
use Proxyma\Domain\Outcomes\CallerOutcomeAction;
use Proxyma\Tests\Support\CatalogTestCase;
use Proxyma\Tests\Support\ClaimFixtures;
use Proxyma\Tests\Support\DemandFixtures;
use Proxyma\Tests\Support\HealthFixtures;
use Proxyma\Tests\Support\QuotaFixtures;
use Proxyma\Tests\Support\RecoveryFixtures;
use Proxyma\Tests\Support\ReservationFixtures;

foreach (['CatalogTestCase', 'SelectionFixtures', 'DemandFixtures', 'ReservationFixtures', 'ClaimFixtures', 'QuotaFixtures', 'RecoveryFixtures', 'HealthFixtures'] as $file) {
    require_once __DIR__.'/../Support/'.$file.'.php';
}
uses(CatalogTestCase::class, DemandFixtures::class, ReservationFixtures::class, ClaimFixtures::class, QuotaFixtures::class, RecoveryFixtures::class, HealthFixtures::class)->group('pg');
beforeEach(fn () => $this->initializeHealthFixture());

it('late 429 использует исходные origin и shared egress после edit и retirement, без foreign route inference', function (bool $missingHealth): void {
    $this->narrowCooldowns();
    $other = Uuid::random();
    $foreign = Uuid::random();
    $this->commands()->upsertRoute(new UpsertRouteCommand($other, $this->tenant, 'shared-exit', RouteKind::Direct,
        null, true, EgressIdentity::explicit('nat:fixture'), null, false, $this->actor()));
    $this->commands()->upsertRoute(new UpsertRouteCommand($foreign, $this->tenant, 'foreign-profile-route', RouteKind::Direct,
        null, true, EgressIdentity::explicit('nat:foreign'), null, false, $this->actor()));
    selectionHealth($this->db, $this->tenant);
    if ($missingHealth) {
        $this->table('route_health')->where('route_id', $foreign->value)->delete();
        $this->table('route_target_health')->where('route_id', $foreign->value)->delete();
    }
    $prepared = $this->authorized();
    $receipt = $this->late($prepared);
    $snapshot = json_decode($this->table('attempts')->value('snapshot'), true, flags: JSON_THROW_ON_ERROR);
    expect($snapshot['cooldown_budget_ids'])->toHaveCount(3)->and($snapshot['profile_route_set'])->toBe([$this->catalog['route']->value]);
    $origin = CanonicalOrigin::of('https', 'example.test');
    $newEgress = $this->budget($origin->egressScopeKey('nat:edited'));
    $this->table('routes')->where('id', $this->catalog['route']->value)->update(['egress_key' => 'nat:edited', 'revision' => 2]);
    $this->table('targets')->update(['canonical_origin' => 'https://edited.test:443', 'revision' => 2,
        'retired_at' => $this->db->raw('clock_timestamp()')]);
    foreach (['route_health', 'route_target_health', 'target_health'] as $scope) {
        $this->table($scope)->update(['state' => 'open', 'health_epoch' => 2, 'reason_code' => 'new_hold']);
    }
    $late = new LateReceiptClaim($receipt->operationId, $receipt->physicalAttemptId, $receipt->wireFingerprint, $receipt->receiptId,
        $receipt->receiptDigest, $receipt->authorizationHealthEpochs, $this->healthResponse(429)->response);
    $this->completion->recordLateReceipt($late);
    expect($this->table('budgets')->whereIn('id', $snapshot['cooldown_budget_ids'])->where('cooldown_until', '>', '2030-06-01 10:10:00+00')->count())->toBe(3)
        ->and($this->table('budgets')->where('id', $newEgress)->value('cooldown_until'))->toBe('-infinity')
        ->and($this->table('target_health')->value('reason_code'))->toBe('new_hold')
        ->and($this->table('targets')->value('retired_at'))->not->toBeNull();
    $this->completion->recordLateReceipt($late);
    expect($this->table('observations')->count())->toBe(1);
    // Второй маршрут того же выхода видит тот же durable egress cooldown.
    expect($this->table('routes')->where('id', $other->value)->value('egress_key'))->toBe('nat:fixture')
        ->and($this->table('budgets')->where('scope_key', $origin->egressScopeKey('nat:fixture'))->value('cooldown_until'))->toStartWith('2030-06-01 10:12:00');
})->with([false, true]);

it('Retry-After ограничен DB-time потолком и max никогда не сокращает существующий cooldown', function (?string $header, int $seconds): void {
    $long = $this->authorized();
    $short = $this->authorized();
    [, $input] = $long;
    $this->completion->record($input->operation->operationId, $input->request->physicalAttemptId, $this->healthResponse(429, $header));
    $until = $this->table('budgets')->where('id', $this->originBudget->value)->value('cooldown_until');
    expect(new DateTimeImmutable($until))->toEqual(new DateTimeImmutable('2030-06-01T10:10:00Z +'.$seconds.' seconds'));
    [, $input] = $short;
    $this->completion->record($input->operation->operationId, $input->request->physicalAttemptId, $this->healthResponse(429, '0'));
    expect($this->table('budgets')->where('id', $this->originBudget->value)->value('cooldown_until'))->toBe($until);
})->with(['huge' => [str_repeat('9', 120), 86400], 'invalid' => ['later', 60], 'absent' => [null, 60],
    'past date' => ['Sun, 01 Jan 2023 00:00:00 GMT', 1], 'http date' => ['Sat, 01 Jun 2030 10:12:00 GMT', 120]]);

it('403 использует registered interpretation из authorization, даже если registry заменён до receipt', function (bool $enabled): void {
    $registry = new OutcomePolicyRegistry;
    $registry->register('default', fn () => CallerOutcomeAction::Accept, wafRateSignal: $enabled)->freeze();
    $this->app->instance(OutcomePolicyRegistry::class, $registry);
    $this->app->forgetInstance(ClaimSend::class);
    $this->claims = $this->app->make(ClaimSend::class);
    [, $input] = $this->authorized();
    $this->app->instance(OutcomePolicyRegistry::class, new OutcomePolicyRegistry);
    $this->completion->record($input->operation->operationId, $input->request->physicalAttemptId, $this->healthResponse(403));
    expect($this->table('route_target_health')->value('state'))->toBe($enabled ? 'open' : 'closed')
        ->and($this->table('route_target_health')->value('reason_code'))->toBe($enabled ? 'waf_rate_signal' : null)
        ->and($this->table('budgets')->where('id', $this->originBudget->value)->value('cooldown_until'))->toBe('-infinity');
    $summary = json_decode($this->table('attempts')->value('result_summary'), true);
    expect($summary['applies_waf_hold'])->toBe($enabled)->and($summary)->not->toHaveKey('_retry_after');
})->with([false, true]);

it('credential hold переживает success и timer, снимается только rotation своего endpoint/version', function (bool $secretRotation): void {
    $this->proxyClaimFixture();
    $this->table('endpoints')->update(['credential_ref' => null, 'credential_version' => null, 'revision' => 2]);
    $oldSuccess = $this->authorized();
    $oldAuth = $this->authorized();
    [, $input] = $this->authorized();
    $this->completion->record($input->operation->operationId, $input->request->physicalAttemptId, $this->healthResponse(407));
    $hold = $this->table('route_health')->first();
    expect($hold->reason_code)->toBe('credential_hold:1')->and($hold->credential_endpoint_id)->toBe($this->proxyEndpoint->value);
    $this->completion->recordLateReceipt($this->late($oldSuccess));
    expect($this->table('route_health')->first())->toEqual($hold);
    $this->moveSqlClock('2030-06-02T10:10:00Z');
    $intent = $this->quotaIntent(purpose: DemandPurpose::Probe);
    expect(fn () => $this->probes->claim($this->reservationInput($intent)))->toThrow(AdmissionRejected::class, 'credential_hold');
    $other = Uuid::random();
    $this->commands()->upsertEndpoint(new UpsertEndpointCommand($other, $this->tenant, ProxyProtocol::Http, 'other.test', 8080,
        new CredentialRef('fixture:other', 1), $this->actor()));
    $this->commands()->upsertEndpoint(new UpsertEndpointCommand($other, $this->tenant, ProxyProtocol::Http, 'other.test', 8080,
        new CredentialRef('fixture:other-new', 2), $this->actor(1)));
    expect($this->table('route_health')->first())->toEqual($hold);
    if ($secretRotation) {
        $this->app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $this->app->make(RotateCredentials::class)->handle($this->proxyEndpoint, $this->tenant, null, 'fixture-new-secret', $this->actor(2));
    } else {
        $this->commands()->upsertEndpoint(new UpsertEndpointCommand($this->proxyEndpoint, $this->tenant, ProxyProtocol::Http, 'proxy.test', 8080,
            new CredentialRef('fixture:rotated', 2), $this->actor(2)));
    }
    $released = $this->table('route_health')->first();
    expect($released->state)->toBe('unknown')->and($released->credential_endpoint_id)->toBeNull()
        ->and((int) $released->health_epoch)->toBe((int) $hold->health_epoch + 1);
    [, $input] = $oldAuth;
    $this->completion->record($input->operation->operationId, $input->request->physicalAttemptId, $this->healthResponse(407));
    expect($this->table('route_health')->first())->toEqual($released);
    expect(fn () => $this->commands()->upsertEndpoint(new UpsertEndpointCommand($this->proxyEndpoint, $this->tenant, ProxyProtocol::Http,
        'proxy.test', 8080, null, $this->actor(3))))->toThrow(CatalogInvalidMutation::class);
})->with([false, true]);

it('reset требует permission и expected revision, аудируется, replay идемпотентен и stale success fenced', function (): void {
    $old = $this->authorized();
    $this->table('route_health')->update(['state' => 'open', 'health_epoch' => 2, 'state_version' => 2, 'reason_code' => 'credential_hold:1']);
    $id = new Uuid($this->table('route_health')->value('id'));
    $actor = $this->actor(2, 'health-reset');
    $this->commands(false);
    expect(fn () => $this->app->make(ResetHealth::class)->handle(HealthScope::Route, $id, $this->tenant, $actor))->toThrow(RuntimeException::class, 'denied');
    $this->commands();
    $reset = $this->app->make(ResetHealth::class);
    expect(fn () => $reset->handle(HealthScope::Route, $id, $this->tenant, $this->actor(1)))->toThrow(CatalogConflict::class);
    $outcome = $reset->handle(HealthScope::Route, $id, $this->tenant, $actor);
    expect($reset->handle(HealthScope::Route, $id, $this->tenant, $actor))->toEqual($outcome)
        ->and($this->table('audit_events')->where('ability', 'health.reset')->count())->toBe(1)
        ->and($this->table('route_health')->value('state'))->toBe('unknown');
    expect(fn () => $reset->handle(HealthScope::Target, $id, $this->tenant, $actor))->toThrow(CatalogConflict::class);
    $before = $this->table('route_health')->first();
    $this->completion->recordLateReceipt($this->late($old));
    expect($this->table('route_health')->first())->toEqual($before);
});

it('scope retention сохраняет исходные бюджеты и unresolved receipts за horizon без resurrection', function (): void {
    $this->narrowCooldowns();
    $prepared = $this->authorized();
    $snapshot = json_decode($this->table('attempts')->value('snapshot'), true);
    $this->table('routes')->update(['egress_key' => 'nat:new', 'revision' => 2]);
    $this->table('targets')->update(['retired_at' => $this->db->raw('clock_timestamp()'), 'revision' => 2]);
    $this->moveSqlClock('2030-08-01T10:10:00Z');
    $this->reconciler->run($this->tenant);
    $this->table('operations')->update(['status' => 'expired', 'revision' => 2]);
    expect($this->pruner->prune($this->tenant, 2592000))->toBe(0)
        ->and($this->table('budgets')->whereIn('id', $snapshot['cooldown_budget_ids'])->count())->toBe(3);
    $receipt = $this->late($prepared);
    $late = new LateReceiptClaim($receipt->operationId, $receipt->physicalAttemptId, $receipt->wireFingerprint, $receipt->receiptId,
        $receipt->receiptDigest, $receipt->authorizationHealthEpochs, $this->healthResponse(429)->response);
    expect($this->completion->recordLateReceipt($late)->receiptPersisted)->toBeTrue()
        ->and($this->table('budgets')->whereIn('id', $snapshot['cooldown_budget_ids'])->where('cooldown_until', '>', '2030-08-01 10:10:00+00')->count())->toBe(3)
        ->and($this->table('targets')->value('retired_at'))->not->toBeNull();
});
