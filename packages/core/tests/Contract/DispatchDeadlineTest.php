<?php

declare(strict_types=1);

use Proxyma\Application\Admission\ClaimSend;
use Proxyma\Application\Admission\TransactionCoordinator;
use Proxyma\Application\Recovery\Reconciler;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Execution\AttemptResult;
use Proxyma\Domain\Execution\ProxyDestination;
use Proxyma\Domain\Execution\RequestTimeoutPolicy;
use Proxyma\Domain\Execution\SealedWire;
use Proxyma\Domain\Execution\TransportFailure;
use Proxyma\Domain\Execution\WireBody;
use Proxyma\Infrastructure\Http\PhysicalHttpTransport;
use Proxyma\Tests\Support\CatalogTestCase;
use Proxyma\Tests\Support\ClaimFixtures;
use Proxyma\Tests\Support\DemandFixtures;
use Proxyma\Tests\Support\PhysicalFixtures;
use Proxyma\Tests\Support\QuotaFixtures;
use Proxyma\Tests\Support\ReservationFixtures;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

foreach (['CatalogTestCase', 'SelectionFixtures', 'DemandFixtures', 'ReservationFixtures', 'ClaimFixtures', 'QuotaFixtures', 'PhysicalFixtures'] as $file) {
    require_once __DIR__.'/../Support/'.$file.'.php';
}
uses(CatalogTestCase::class, DemandFixtures::class, ReservationFixtures::class, ClaimFixtures::class, QuotaFixtures::class, PhysicalFixtures::class)->group('pg');
beforeEach(function (): void {
    $this->initializePhysicalFixture();
});
afterEach(function (): void {
    foreach ($this->socketWorkers ?? [] as $worker) {
        if ($worker->isRunning()) {
            $worker->stop(0.1, 9);
        }
    }
    $this->socket?->stop();
});

it('latestStart после authorization запрещает activation или physical start с нулём socket bytes', function (bool $beforeActivation): void {
    $now = hrtime(true);
    $this->transactions = new TransactionCoordinator($this->transactions->scope, function () use (&$now): int {
        return $now;
    });
    $this->app->instance(TransactionCoordinator::class, $this->transactions);
    $this->app->forgetInstance(ClaimSend::class);
    $this->claims = $this->app->make(ClaimSend::class);
    $prepared = $this->physicalPrepared();
    [$permit, $input, $wire] = $prepared;
    $candidate = $this->claims->claim($permit, $input, $wire);
    $cap = $beforeActivation ? null : $this->claims->activate($candidate);
    $now = $candidate->conservativeLatestStartNs($wire->request->timeouts->totalWithCleanupMarginNs()) + 1;
    expect(fn () => $beforeActivation ? $this->claims->activate($candidate) : $this->transport->sendOnce($cap))->toThrow(LogicException::class, 'expired');
    $this->socket->assertNoConnections();
    expect($this->table('attempts')->value('dispatch_outcome'))->toBe('not_dispatched')
        ->and((int) $this->table('quota_windows')->sum('spent'))->toBe(2)
        ->and(fn () => $this->claims->claim(...$prepared))->toThrow(AdmissionRejected::class, 'already_claimed');
})->with([true, false]);

it('active SQL transaction запрещает physical preparation и consume без socket bytes', function (): void {
    $prepared = $this->physicalPrepared();
    $cap = $this->physicalCapability($prepared);
    $this->db->beginTransaction();
    try {
        expect(fn () => $this->transport->sendOnce($cap))->toThrow(LogicException::class, 'transactionLevel zero');
        $this->socket->assertNoConnections();
    } finally {
        $this->db->rollBack();
    }
    expect($this->transport->sendOnce($cap)->wasDelivered())->toBeTrue()->and($this->socket->events('origin'))->toHaveCount(1);
});

it('изменённый body после seal не получает claim и не отправляет socket bytes', function (): void {
    [$permit, $input, $wire] = $this->physicalPrepared();
    $changed = new SealedWire($this->modifiedWire($wire->request, ['body' => WireBody::fromString('changed-body')]),
        $wire->route, $wire->destination, $wire->tls, $wire->credential, $wire->proxyTls, $wire->proxy);
    expect(fn () => $this->claims->claim($permit, $input, $changed))->toThrow(AdmissionRejected::class);
    expect($this->table('attempts')->count())->toBe(0);
    $this->socket->assertNoConnections();
});

it('изменённый CA bundle отвергается до consume и socket bytes', function (): void {
    $bundle = $this->socket->root.'/sealed-ca.pem';
    copy($this->socket->certificates.'/ca.pem', $bundle);
    $cap = $this->physicalCapability($this->physicalPrepared(listener: 'tls', originBundle: $bundle));
    file_put_contents($bundle, 'changed CA');
    expect(fn () => $this->transport->sendOnce($cap))->toThrow(LogicException::class, 'CA bundle changed');
    $this->socket->assertNoConnections();
});

it('proxy без sealed address отклоняется без позднего catalog или DNS fallback', function (): void {
    $cap = $this->physicalCapability($this->physicalPrepared(proxyListener: 'proxy', pinProxy: false));
    expect(fn () => $this->transport->sendOnce($cap))->toThrow(LogicException::class, 'sealed locally resolved endpoint');
    $this->socket->assertNoConnections();
});

it('подменённый proxy endpoint при том же revision не получает claim', function (): void {
    $prepared = $this->physicalPrepared(proxyListener: 'proxy', proxyPortOffset: 1);
    expect(fn () => $this->physicalCapability($prepared))->toThrow(AdmissionRejected::class, 'prepared_proxy_conflict');
    expect($this->table('attempts')->count())->toBe(0);
    $this->socket->assertNoConnections();
    $proxy = $prepared[2]->proxy;
    expect(fn (): ProxyDestination => new ProxyDestination($proxy->endpoint, '127.0.0.2'))->toThrow(Error::class)
        ->and(fn (): string => serialize($proxy))->toThrow(LogicException::class);
    $class = ProxyDestination::class;
    expect(fn (): mixed => unserialize('O:'.strlen($class).':"'.$class.'":0:{}'))->toThrow(LogicException::class);
});

it('cancellation запрещает первый send и останавливает actual stalled socket без retry', function (bool $during): void {
    $prepared = $this->physicalPrepared($during ? '/stall' : '/ok', timeouts: new RequestTimeoutPolicy(500, 1000, 1500, 0));
    $cap = $this->physicalCapability($prepared);
    $start = hrtime(true);
    $transport = new PhysicalHttpTransport($this->transactions->scope, $this->sealer, cancelled: static fn (): bool => ! $during || hrtime(true) - $start > 100_000_000);
    expect($transport->sendOnce($cap)->failure)->toBe(TransportFailure::Cancelled)->and($cap->isConsumed())->toBeTrue();
    if ($during) {
        expect($this->socket->events('origin'))->toHaveCount(1);
    } else {
        $this->socket->assertNoConnections();
    }
    expect(fn (): AttemptResult => $transport->sendOnce($cap))->toThrow(LogicException::class, 'already consumed');
})->with([true, false]);

function physicalWorker($test, array $prepared, string $checkpoint): Process
{
    [$permit, $input, $wire] = $prepared;
    $worker = new Process([PHP_BINARY, __DIR__.'/../Support/QuotaWorker.php', json_encode([
        'schema' => $test->wireSchema, 'label' => 'one', 'operation' => $input->operation->operationId->value,
        'attempt' => $input->request->physicalAttemptId->value, 'route' => $input->selection->routeId->value,
        'permit' => $permit->permitId->value, 'classes' => [], 'physical_checkpoint' => $checkpoint,
        'url' => $wire->request->url->reveal(), 'deadline' => $wire->request->deadlineAt->format('U.u'),
    ], JSON_THROW_ON_ERROR)]);
    $worker->setTimeout(10);
    $test->socketWorkers[] = $worker;
    $worker->start();
    if (! $worker->waitUntil(static fn ($type, $output): bool => str_contains($output, 'authorized'))) {
        throw new RuntimeException('Socket worker failed: '.$worker->getErrorOutput().$worker->getOutput());
    }

    return $worker;
}

it('SIGKILL после authorization и после actual origin bytes оставляет unknown без refund или повторной отправки', function (string $checkpoint): void {
    $prepared = $this->physicalPrepared('/late', timeouts: new RequestTimeoutPolicy(500, 3000, 4000, 0, maxAttempts: 3, maxDistinctRoutes: 3));
    $this->wireSchema = $this->fixtureSchema;
    $barrier = $this->connectionPdo();
    $barrier->query("SELECT pg_advisory_lock(hashtextextended('fixture:physical-start',0))");
    try {
        $worker = physicalWorker($this, $prepared, $checkpoint);
        if ($checkpoint === 'after_authorization') {
            $this->socket->assertNoConnections();
        } else {
            $this->socket->await('origin');
            expect($this->socket->events('origin')[0]['body_hash'])->toBe(hash('sha256', 'sealed-body'));
        }
        $worker->signal(9);
        try {
            $worker->wait();
        } catch (ProcessSignaledException $signal) {
            expect($signal->getSignal())->toBe(9);
        }
        expect($worker->getTermSignal())->toBe(9)->and($this->table('attempts')->value('receipt_digest'))->toBeNull();
        $this->sqlClock(new DateTimeImmutable('+1 hour')->format(DATE_ATOM));
        $reconciler = $this->app->make(Reconciler::class);
        $reconciler->run($this->tenant);
        expect($this->table('permits')->value('state'))->toBe('unknown_after_authorization')
            ->and((int) $this->table('budgets')->sum('inflight'))->toBe(0)
            ->and((int) $this->table('quota_windows')->sum('spent'))->toBe(2)
            ->and($this->socket->events('origin'))->toHaveCount($checkpoint === 'after_authorization' ? 0 : 1)
            ->and(fn () => $this->claims->claim(...$prepared))->toThrow(AdmissionRejected::class);
    } finally {
        $barrier->query('SELECT pg_advisory_unlock_all()');
    }
})->with(['after_authorization', 'after_bytes']);

it('поздний actual socket result после inflight expiry сохраняется без второго release и quota refund', function (): void {
    $prepared = $this->physicalPrepared('/late', timeouts: new RequestTimeoutPolicy(500, 3000, 4000, 0, maxAttempts: 3, maxDistinctRoutes: 3));
    $this->wireSchema = $this->fixtureSchema;
    $worker = physicalWorker($this, $prepared, 'late_result');
    $this->socket->await('origin');
    expect($this->table('attempts')->value('receipt_digest'))->toBeNull();
    $this->sqlClock(new DateTimeImmutable('+1 hour')->format(DATE_ATOM));
    $reconciler = $this->app->make(Reconciler::class);
    $reconciler->run($this->tenant);
    expect($this->table('permits')->value('state'))->toBe('unknown_after_authorization')
        ->and((int) $this->table('budgets')->sum('inflight'))->toBe(0);
    $this->socket->release();
    $worker->wait();
    expect($worker->getExitCode())->toBe(0, $worker->getErrorOutput())
        ->and($this->table('permits')->value('state'))->toBe('completed')
        ->and($this->table('attempts')->value('receipt_digest'))->not->toBeNull()
        ->and((int) $this->table('budgets')->sum('inflight'))->toBe(0)
        ->and((int) $this->table('quota_windows')->sum('spent'))->toBe(2)
        ->and($this->table('observations')->count())->toBe(1)->and($this->socket->events('origin'))->toHaveCount(1);
    $summary = $this->table('attempts')->value('result_summary');
    expect($summary)->not->toContain('sealed-body', 'fixture-provider-secret', 'fixture-response')
        ->and(json_decode($summary, true)['body_hash'])->toBe(hash('sha256', 'fixture-response'));
});
