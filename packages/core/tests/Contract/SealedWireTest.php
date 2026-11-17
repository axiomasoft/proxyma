<?php

declare(strict_types=1);

use Carbon\Carbon;
use Proxyma\Application\Admission\AuthorityLocker;
use Proxyma\Application\Admission\ClaimSend;
use Proxyma\Application\Admission\TransactionCoordinator;
use Proxyma\Application\Execution\WireSealer;
use Proxyma\Contracts\Admission\OwnerAuthority;
use Proxyma\Contracts\Policy\PolicyCommands;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Catalog\EgressIdentity;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Execution\AuthorizedWireRequest;
use Proxyma\Domain\Execution\ClaimCandidate;
use Proxyma\Domain\Execution\CommitLatch;
use Proxyma\Domain\Execution\PreparedHeaders;
use Proxyma\Domain\Execution\ResolvedSecret;
use Proxyma\Domain\Execution\ResolvedUrl;
use Proxyma\Domain\Execution\RouteSelection;
use Proxyma\Domain\Execution\SafeUrl;
use Proxyma\Domain\Execution\SealedWire;
use Proxyma\Domain\Execution\TlsTrustConfiguration;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireBody;
use Proxyma\Domain\Policy\PolicyScope;
use Proxyma\Domain\Policy\UpdatePolicyCommand;
use Proxyma\Infrastructure\Admission\ReservationCatalogLocks;
use Proxyma\Tests\Support\CatalogTestCase;
use Proxyma\Tests\Support\ClaimFixtures;
use Proxyma\Tests\Support\DemandFixtures;
use Proxyma\Tests\Support\ReservationFixtures;

require_once __DIR__.'/../Support/CatalogTestCase.php';
require_once __DIR__.'/../Support/SelectionFixtures.php';
require_once __DIR__.'/../Support/DemandFixtures.php';
require_once __DIR__.'/../Support/ReservationFixtures.php';
require_once __DIR__.'/../Support/ClaimFixtures.php';

uses(CatalogTestCase::class, DemandFixtures::class, ReservationFixtures::class, ClaimFixtures::class)->group('pg');

beforeEach(function (): void {
    $this->initializeClaimFixture();
});

it('создаёт inert candidate, затем после outer commit выдаёт единственную immutable capability', function (): void {
    [$permit, $input, $wire] = $this->prepareClaim();
    $before = $this->accounting();
    $candidate = $this->claims->claim($permit, $input, $wire);
    expect($candidate)->toBeInstanceOf(ClaimCandidate::class)->and($this->emitted)->toBe(0)
        ->and($this->table('attempts')->count())->toBe(1)->and($this->table('operations')->value('authorized_attempts'))->toBe(1);
    foreach ($this->accounting() as $index => $budget) {
        expect($budget['tat'])->toBe($before[$index]['tat'])->and($budget['send_tat'])->not->toBe('-infinity')->and($budget['inflight'])->toBe(1);
    }
    expect($this->table('reservations')->where('state', 'spent')->count())->toBe(3);
    $capability = $this->claims->activate($candidate);
    $this->emit($capability);
    expect($this->emitted)->toBe(1);
    expect(fn () => $this->emit($capability))->toThrow(LogicException::class, 'already consumed');
    expect(fn () => $this->claims->activate($candidate))->toThrow(LogicException::class, 'already been activated');
    expect(fn (): object => clone $capability)->toThrow(LogicException::class);
    expect(fn (): string => serialize($capability))->toThrow(LogicException::class);
    expect(fn (): string => serialize($candidate))->toThrow(LogicException::class);
    expect(fn (): object => clone $candidate)->toThrow(LogicException::class);
    expect(fn (): string => json_encode($candidate, JSON_THROW_ON_ERROR))->toThrow(LogicException::class);
    expect(fn (): string => json_encode($capability, JSON_THROW_ON_ERROR))->toThrow(LogicException::class);
    foreach ([$capability, $candidate, $wire->request, $wire] as $runtime) {
        $class = $runtime::class;
        expect(fn (): mixed => unserialize('O:'.strlen($class).':"'.$class.'":0:{}'))->toThrow(LogicException::class);
    }
    expect(fn () => $this->claims->claim($permit, $input, $wire))->toThrow(AdmissionRejected::class, 'already_claimed');
    expect($this->emitted)->toBe(1)->and($this->table('attempts')->count())->toBe(1);
});

it('отклоняет изменение sealed HTTP intent без bytes или нового send debt', function (string $change): void {
    [$permit, $input, $wire] = $this->prepareClaim();
    $changes = match ($change) {
        'url' => ['url' => new SafeUrl('https://example.test/other')],
        'method' => ['method' => 'HEAD'],
        'header' => ['headers' => new PreparedHeaders(['authorization' => 'fixture-secret-header'])],
        'body' => ['method' => 'POST', 'body' => WireBody::fromString('different')],
        'policy' => ['policyRevision' => 2],
        'deadline' => ['deadlineAt' => $wire->request->deadlineAt->modify('+1 hour')],
    };
    $changed = new SealedWire($this->modifiedWire($wire->request, $changes), $wire->route, $wire->destination, $wire->tls);
    $before = $this->accounting();
    expect(fn () => $this->claims->claim($permit, $input, $changed))->toThrow(AdmissionRejected::class);
    expect($this->accounting())->toBe($before)->and($this->table('attempts')->count())->toBe(0)->and($this->emitted)->toBe(0);
})->with(['url', 'method', 'header', 'body', 'policy', 'deadline']);

it('не активирует candidate после savepoint release, outer rollback или commit failure', function (string $finish): void {
    [$permit, $input, $wire] = $this->prepareClaim();
    $before = $this->accounting();
    $this->db->beginTransaction();
    $this->db->beginTransaction();
    $candidate = $this->transactions->locked($this->db, fn ($tx) => $this->claims->claimSendLocked($permit, $input, $wire, $tx));
    expect(fn () => $this->claims->activate($candidate))->toThrow(LogicException::class);
    if ($finish === 'savepoint_rollback') {
        $this->db->rollBack();
        $this->db->commit();
    } else {
        $this->db->commit();
        expect(fn () => $this->claims->activate($candidate))->toThrow(LogicException::class);
        if ($finish === 'outer_rollback') {
            $this->db->rollBack();
        } elseif ($finish === 'commit_failure') {
            $this->db->statement('CREATE TABLE fixture_deferred_parent (id int PRIMARY KEY)');
            $this->db->statement('CREATE TABLE fixture_deferred_child (id int REFERENCES fixture_deferred_parent(id) DEFERRABLE INITIALLY DEFERRED)');
            $this->db->statement('INSERT INTO fixture_deferred_child VALUES (1)');
            try {
                $this->db->commit();
                throw new RuntimeException('Expected deferred FK commit failure.');
            } catch (PDOException) {
                $this->db->rollBack();
            }
        } else {
            $this->db->commit();
            $this->emit($this->claims->activate($candidate));
            expect($this->emitted)->toBe(1);

            return;
        }
    }
    expect(fn () => $this->claims->activate($candidate))->toThrow(LogicException::class);
    expect($this->accounting())->toBe($before)->and($this->table('attempts')->count())->toBe(0)->and($this->emitted)->toBe(0);
})->with(['savepoint_rollback', 'outer_rollback', 'commit_failure', 'outer_commit']);

it('проверяет transaction level непосредственно при consume и не допускает supplied clock', function (): void {
    [$permit, $input, $wire] = $this->prepareClaim();
    $capability = $this->claims->activate($this->claims->claim($permit, $input, $wire));
    $this->db->beginTransaction();
    expect(fn () => $this->emit($capability))->toThrow(LogicException::class, 'transactionLevel zero');
    $this->db->rollBack();
    expect(fn () => $capability->consume(1))->toThrow(LogicException::class, 'caller clock');
    $this->emit($capability);
    expect($this->emitted)->toBe(1);
});

it('keyed headers и body не раскрываются в debug и serialization', function (): void {
    $intent = $this->demandOperation();
    $intent['request'] = $this->modifiedWire($intent['request'], ['method' => 'POST', 'headers' => new PreparedHeaders(['Authorization' => 'secret-fixture-42']),
        'body' => WireBody::fromString('body-fixture-42')]);
    [$permit, $input, $wire] = $this->prepareClaim($intent);
    $cap = $this->claims->activate($this->claims->claim($permit, $input, $wire));
    ob_start();
    var_dump($wire, $cap, $wire->request->headers, $wire->request->body, $this->sealer);
    $debug = ob_get_clean();
    expect($debug)->not->toContain('secret-fixture-42', 'body-fixture-42', 'fixture-only-32-byte-hmac-key-value');
    expect(fn (): string => serialize($wire))->toThrow(LogicException::class);
    foreach ([$wire->request->headers, $wire->request->body] as $runtime) {
        $class = $runtime::class;
        expect(fn (): mixed => unserialize('O:'.strlen($class).':"'.$class.'":0:{}'))->toThrow(LogicException::class);
    }
    expect(fn (): PreparedHeaders => new PreparedHeaders(['Authorization' => 'one', 'authorization' => 'two']))->toThrow(InvalidArgumentException::class);
    expect($this->table('attempts')->value('snapshot'))->not->toContain('secret-fixture-42', 'body-fixture-42');
});

it('refusal после latestStart не возвращает charge и не разрешает reclaim', function (bool $beforeActivation): void {
    $now = hrtime(true);
    $coordinator = new TransactionCoordinator($this->transactions->scope, function () use (&$now): int {
        return $now;
    });
    $this->app->instance(TransactionCoordinator::class, $coordinator);
    $this->app->forgetInstance(ClaimSend::class);
    $this->claims = $this->app->make(ClaimSend::class);
    [$permit, $input, $wire] = $this->prepareClaim();
    $candidate = $this->claims->claim($permit, $input, $wire);
    $spent = $this->accounting();
    $cap = $beforeActivation ? null : $this->claims->activate($candidate);
    $now = $candidate->conservativeLatestStartNs($input->request->timeouts->totalWithCleanupMarginNs()) + 1;
    expect(fn () => $beforeActivation ? $this->claims->activate($candidate) : $this->emit($cap))->toThrow(LogicException::class, 'expired');
    expect($this->emitted)->toBe(0)->and($this->accounting())->toBe($spent)
        ->and($this->table('permits')->value('state'))->toBe('unknown_after_authorization')
        ->and($this->table('attempts')->value('dispatch_outcome'))->toBe('not_dispatched')
        ->and($this->table('attempts')->value('dispatch_refused_at'))->not->toBeNull();
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_10_000007_add_dispatch_refusal_evidence.php';
    expect(fn () => $migration->down())->toThrow(LogicException::class, 'history prevents rollback');
    expect(fn () => $this->claims->claim($permit, $input, $wire))->toThrow(AdmissionRejected::class, 'already_claimed');
    expect(fn () => $this->claims->activate($candidate))->toThrow(LogicException::class);
})->with([true, false]);

it('полный fingerprint не допускает иной route, credential, pinned address или TLS', function (string $change): void {
    $this->proxyClaimFixture();
    [$permit, $input, $wire] = $this->prepareClaim();
    $route = $wire->route;
    $destination = $wire->destination;
    $tls = $wire->tls;
    $credential = $wire->credential;
    if ($change === 'route') {
        $route = new Route(...array_replace(get_object_vars($route), ['id' => Uuid::random()]));
    } elseif ($change === 'credential') {
        $credential = new ResolvedSecret($credential->reference, 'fixture', 'changed-secret');
    } elseif ($change === 'address') {
        $destination = new ResolvedUrl($wire->request->url, '192.0.2.2');
    } else {
        $tls = new TlsTrustConfiguration(caBundlePath: '/fixture/another-ca');
    }
    $changed = new SealedWire($wire->request, $route, $destination, $tls, $credential);
    $before = $this->accounting();
    expect(fn () => $this->claims->claim($permit, $input, $changed))->toThrow(AdmissionRejected::class);
    expect($this->accounting())->toBe($before)->and($this->emitted)->toBe(0)->and($this->table('attempts')->count())->toBe(0);
})->with(['route', 'credential', 'address', 'tls']);

it('denied CAS откатывает предшествующую finance mutation и не зависит от application wall clock', function (): void {
    [$permit, $input, $wire] = $this->prepareClaim();
    $this->db->statement('CREATE TABLE fixture_claim_finance (reserved int NOT NULL)');
    $this->db->statement('INSERT INTO fixture_claim_finance VALUES (0)');
    $before = $this->accounting();
    $this->table('operations')->where('id', $input->operation->operationId->value)->update(['deadline' => $this->db->raw("clock_timestamp() - interval '1 second'")]);
    Carbon::setTestNow('1900-01-01');
    try {
        expect(fn () => $this->transactions->run(function ($tx) use ($permit, $input, $wire): void {
            $this->db->table('fixture_claim_finance')->increment('reserved');
            $this->claims->claimSendLocked($permit, $input, $wire, $tx);
        }))->toThrow(AdmissionRejected::class);
    } finally {
        Carbon::setTestNow();
    }
    expect($this->db->table('fixture_claim_finance')->value('reserved'))->toBe(0)
        ->and($this->accounting())->toBe($before)->and($this->emitted)->toBe(0);
});

it('новый policy head или binding после подготовки дают configuration hold без charges', function (string $change): void {
    $head = Uuid::random();
    $create = fn () => $this->app->make(PolicyCommands::class)->update(new UpdatePolicyCommand(
        $head, $this->tenant, PolicyScope::Tenant, 'acme',
        '{"schema_version":1,"operations":{"retry.max_attempts":{"op":"set","value":2}}}', $this->actor()));
    if ($change === 'binding') {
        $create();
    }
    [$permit, $input, $wire] = $this->prepareClaim();
    $before = $this->accounting();
    if ($change === 'head') {
        $create();
    } else {
        $this->table('bindings')->insert(['id' => Uuid::random()->value, 'tenant_key' => 'acme',
            'subject_type' => 'document', 'subject_id' => 'fixture', 'head_id' => $head->value, 'profile_id' => $this->catalog['profile']->value]);
    }
    expect(fn () => $this->claims->claim($permit, $input, $wire))->toThrow(AdmissionRejected::class, 'policy_or_binding_changed');
    expect($this->accounting())->toBe($before)->and($this->table('attempts')->count())->toBe(0)->and($this->emitted)->toBe(0);
})->with(['head', 'binding']);

it('повторно проверяет live target, route, health, owner и полный budget set', function (string $change): void {
    [$permit, $input, $wire] = $this->prepareClaim();
    match ($change) {
        'target' => $this->table('targets')->update(['retired_at' => $this->db->raw('clock_timestamp()'), 'revision' => 2]),
        'route' => $this->table('routes')->update(['enabled' => false, 'revision' => 2]),
        'route_epoch' => $this->table('route_health')->update(['health_epoch' => 2, 'state_version' => 2]),
        'target_health' => $this->table('target_health')->update(['availability' => 'unavailable', 'state_version' => 2]),
        'generation' => $this->table('operations')->update(['owner_generation' => 2, 'revision' => 2]),
        'budget' => $this->table('budgets')->update(['revision' => 2]),
        'send_debt' => $this->table('budgets')->where('scope_key', $this->scopeKey)->update(['send_tat' => $this->db->raw("clock_timestamp() + interval '1 minute'")]),
    };
    $before = $this->accounting();
    expect(fn () => $this->claims->claim($permit, $input, $wire))->toThrow(AdmissionRejected::class);
    expect($this->accounting())->toBe($before)->and($this->table('attempts')->count())->toBe(0)->and($this->emitted)->toBe(0);
})->with(['target', 'route', 'route_epoch', 'target_health', 'generation', 'budget', 'send_debt']);

it('manual latch и реконструкция persisted authorization не создают capability', function (): void {
    [$permit, $input, $wire] = $this->prepareClaim();
    $candidate = $this->claims->claim($permit, $input, $wire);
    $cap = $this->claims->activate($candidate);
    $latch = new CommitLatch('pgsql', 'forged', hrtime(true));
    $forged = $latch->issueCandidate($permit->permitId, $input->request->operationId, $input->request->physicalAttemptId,
        $input->request->fingerprint(), $candidate->authorizedAt, $candidate->expiresAt, 1);
    $latch->markOutermostCommitted(0);
    expect(fn (): AuthorizedWireRequest => AuthorizedWireRequest::activate($forged, $latch, 0, $wire->request, $cap->snapshot))
        ->toThrow(LogicException::class, 'activation authority');
    expect(fn () => $this->transactions->run(function ($tx) use ($candidate, $wire, $cap): void {
        $copy = $this->transactions->issueCandidate($candidate->permitId, $candidate->operationId, $candidate->physicalAttemptId,
            $candidate->wireFingerprint, $candidate->authorizedAt, $candidate->expiresAt, $tx);
        $this->transactions->bindAuthorization($copy, $cap->snapshot, static fn (): null => null, $wire, $this->sealer);
    }))->toThrow(LogicException::class, 'Missing durable');
    $this->rejectedSql(fn () => $this->table('attempts')->update(['wire_fingerprint' => str_repeat('a', 64)]));
    expect($this->emitted)->toBe(0)->and($this->table('attempts')->count())->toBe(1);
});

it('один durable claim нельзя связать со вторым candidate того же outer transaction', function (): void {
    [$permit, $input, $wire] = $this->prepareClaim();
    $candidate = $this->transactions->run(function ($tx) use ($permit, $input, $wire) {
        $candidate = $this->claims->claimSendLocked($permit, $input, $wire, $tx);
        $copy = $this->transactions->issueCandidate($candidate->permitId, $candidate->operationId, $candidate->physicalAttemptId,
            $candidate->wireFingerprint, $candidate->authorizedAt, $candidate->expiresAt, $tx);
        $bindings = new ReflectionProperty($this->transactions, 'authorizations')->getValue($this->transactions);
        expect(fn () => $this->transactions->bindAuthorization($copy, $bindings[$candidate][0], static fn (): null => null, $wire, $this->sealer))
            ->toThrow(LogicException::class, 'duplicate binding');

        return $candidate;
    });
    $this->emit($this->claims->activate($candidate));
    expect($this->emitted)->toBe(1)->and($this->table('attempts')->count())->toBe(1);
});

it('caller-owned claim требует preflight и отсутствие digest key закрывает grant', function (): void {
    [$permit, $input, $wire] = $this->prepareClaim();
    $before = $this->accounting();
    $this->app->forgetInstance(ClaimSend::class);
    $fresh = $this->app->make(ClaimSend::class);
    expect(fn () => $this->transactions->run(fn ($tx) => $fresh->claimSendLocked($permit, $input, $wire, $tx)))
        ->toThrow(AdmissionRejected::class, 'claim_preparation_required');
    expect(fn (): WireSealer => new WireSealer($this->transactions->scope, ''))
        ->toThrow(AdmissionRejected::class, 'wire_digest_key_required');
    expect($this->accounting())->toBe($before)->and($this->table('attempts')->count())->toBe(0);
});

it('отозванный shared grant проверяется под route mutex до поздних locks', function (): void {
    $route = Uuid::random();
    $grant = Uuid::random();
    $this->table('routes')->insert(['id' => $route->value, 'tenant_key' => 'foreign', 'route_key' => 'fixture-shared',
        'kind' => 'direct', 'enabled' => true, 'egress_key' => 'nat:foreign']);
    $this->table('shared_route_grants')->insert(['id' => $grant->value, 'tenant_key' => 'foreign', 'consumer_tenant_key' => 'acme', 'route_id' => $route->value]);
    $this->table('route_profile_members')->delete();
    $this->table('route_profile_members')->insert(['tenant_key' => 'acme', 'profile_id' => $this->catalog['profile']->value,
        'route_id' => $route->value, 'route_owner_tenant_key' => 'foreign', 'shared_grant_id' => $grant->value, 'position' => 0]);
    $this->table('route_profiles')->update(['revision' => 2]);
    $intent = $this->demandOperation();
    $intent['route'] = new RouteSelection($route, RouteKind::Direct, $this->catalog['profile'], null, 1,
        EgressIdentity::explicit('nat:foreign'));
    $input = $this->reservationInput($intent);
    $this->table('shared_route_grants')->update(['retired_at' => $this->db->raw('clock_timestamp()'), 'revision' => 2]);
    $before = $this->accounting();
    expect(fn () => $this->transactions->run(function ($tx) use ($input): void {
        $locks = $this->app->make(AuthorityLocker::class);
        $locks->owner($this->app->make(OwnerAuthority::class), $input->operation->owner, $input->ownerSnapshot, $tx);
        $locks->operation($input->operation->operationId, $input->operation->owner, $tx);
        $this->app->make(ReservationCatalogLocks::class)->lock($input, $tx);
    }))->toThrow(AdmissionRejected::class, 'shared_grant_changed');
    expect($this->accounting())->toBe($before)->and($this->table('attempts')->count())->toBe(0);
});

it('избыточный policy snapshot даёт bounded configuration hold', function (): void {
    $rows = [];
    for ($i = 0; $i < 1025; $i++) {
        $rows[] = ['tenant_key' => 'acme', 'scope_key' => 'fixture:'.$i];
    }
    $this->table('policy_heads')->insert($rows);
    $intent = $this->demandOperation();
    $before = $this->accounting();
    expect(fn () => $this->reservationInput($intent))->toThrow(AdmissionRejected::class, 'policy_state_bound');
    expect($this->accounting())->toBe($before)->and($this->table('attempts')->count())->toBe(0);
});
