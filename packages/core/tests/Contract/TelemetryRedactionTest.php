<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Proxyma\Application\Diagnostics\OperationsMetrics;
use Proxyma\Contracts\Authorizer;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\DatabaseClock;
use Proxyma\Infrastructure\Telemetry\OperationsTelemetry;
use Proxyma\Tests\Support\CatalogTestCase;
use Proxyma\Tests\Support\ClaimFixtures;
use Proxyma\Tests\Support\DemandFixtures;
use Proxyma\Tests\Support\QuotaFixtures;
use Proxyma\Tests\Support\RecoveryFixtures;
use Proxyma\Tests\Support\ReservationFixtures;
use Psr\Log\AbstractLogger;

foreach (['CatalogTestCase', 'SelectionFixtures', 'DemandFixtures', 'ReservationFixtures', 'ClaimFixtures', 'QuotaFixtures', 'RecoveryFixtures'] as $file) {
    require_once __DIR__.'/../Support/'.$file.'.php';
}
uses(CatalogTestCase::class, DemandFixtures::class, ReservationFixtures::class, ClaimFixtures::class, QuotaFixtures::class, RecoveryFixtures::class);
beforeEach(fn () => $this->initializeRecoveryFixture());

it('telemetry schema отбрасывает secrets/body/URLs и сохраняет только UUID correlation', function (): void {
    $logger = new class extends AbstractLogger
    {
        public array $records = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = [$level, $message, $context];
        }
    };
    $telemetry = new OperationsTelemetry($logger);
    $id = Uuid::random();
    $hostile = ['authorization' => 'Bearer top-secret', 'body' => 'private-body', 'password' => 'proxy-password',
        'url' => 'https://user:password@tenant-secret.test/path-token?api_key=secret', 'tenant' => 'private-tenant',
        'exception' => new RuntimeException('credential-store-password'), 'headers' => ['set-cookie' => 'private-cookie']];
    $telemetry->event('reconcile', $id, 'quota', $hostile);
    $telemetry->event(str_repeat('private-', 10000), null, 'https://user:pw@private.test/?secret=token', $hostile);
    $telemetry->blocked('quota_exhausted', $id);
    $output = json_encode($logger->records, JSON_THROW_ON_ERROR);
    expect($output)->toContain($id->value, 'proxyma.operations', 'proxyma_blocked', 'quota')->not->toContain(
        'top-secret', 'private-body', 'proxy-password', 'path-token', 'private-tenant', 'credential-store-password', 'private-cookie', 'private.test', 'https://');
    expect(strlen($output))->toBeLessThan(1500);
    expect($logger->records[0][2])->toBe(['event' => 'reconcile', 'correlation_id' => $id->value, 'reason' => 'quota']);
});

it('labels имеют конечный набор и не допускают URL/tenant dimensions или произвольных metrics', function (): void {
    $telemetry = $this->app->make(OperationsTelemetry::class);
    $labels = [];
    for ($i = 0; $i < 100; $i++) {
        $sample = $telemetry->sample('blocked', 1, 'tenant-'.$i.' https://secret.test/'.$i);
        expect(array_keys($sample['labels']))->toBe(['reason'])->and($sample['labels']['reason'])->toBe('other');
        $labels[] = json_encode($sample['labels']);
    }
    expect(array_unique($labels))->toHaveCount(1);
    foreach ([-1, INF, NAN] as $value) {
        expect(fn () => $telemetry->sample('claim', $value))->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => $telemetry->sample('https://secret.test/password', 1))->toThrow(InvalidArgumentException::class);
});

it('bounded metrics измеряют queue age, admission wait, claims, unknown и reconcile lag без записей', function (): void {
    [$ticket, $input, $wire] = $this->quotaEnqueued();
    $this->table('demands')->where('id', $ticket->demandId->value)->update(['created_at' => DatabaseClock::now($this->db)]);
    $this->moveSqlClock('2030-06-01T10:10:00.200000Z');
    $permit = $this->reservations->reserve($ticket, $input);
    $this->claims->prepare($permit, $input, $wire);
    $this->claims->claim($permit, $input, $wire);
    $this->quotaEnqueued();
    $this->table('demands')->where('state', 'active')->update(['created_at' => DatabaseClock::now($this->db)]);
    $this->moveSqlClock('2030-06-01T10:12:00Z');
    $queries = [];
    $this->db->listen(function ($event) use (&$queries): void {
        $queries[] = $event->sql;
    });
    $before = $this->accounting();
    $metrics = $this->app->make(OperationsMetrics::class)->snapshot($this->tenant, 1);
    $values = array_column($metrics['metrics'], 'value', 'name');
    expect($metrics['sample_limit'])->toBe(1)->and($metrics['sampled'])->toBeTrue()->and($metrics['lease_inflight_is_physical_sockets'])->toBeFalse()
        ->and($values['proxyma_queue_age_seconds'])->toBeGreaterThan(100)->and($values['proxyma_admission_wait_seconds'])->toBe(0.2)
        ->and($values['proxyma_claim'])->toBe(1.0)->and($values['proxyma_reconcile_lag_seconds'])->toBeGreaterThan(0)
        ->and($values['proxyma_lease_inflight'])->toBe(1.0)->and($this->accounting())->toBe($before);
    foreach ($queries as $sql) {
        expect(strtolower($sql))->not->toMatch('/\b(insert|update|delete)\b/');
    }
    $output = json_encode($metrics, JSON_THROW_ON_ERROR);
    expect($output)->not->toContain('example.test', 'acme', 'fixture-token', 'body', 'password');
    $this->reconciler->run($this->tenant);
    $metrics = $this->app->make(OperationsMetrics::class)->snapshot($this->tenant, 1);
    $values = array_column($metrics['metrics'], 'value', 'name');
    expect($values['proxyma_unknown'])->toBe(1.0)->and($values['proxyma_reconcile_lag_seconds'])->toBe(0.0)
        ->and($values['proxyma_lease_inflight'])->toBe(0.0);
});

it('completed metric учитывает durable receipt и не превращается в lifetime counter', function (): void {
    $prepared = $this->authorized();
    $this->completion->recordLateReceipt($this->late($prepared));
    $metrics = $this->app->make(OperationsMetrics::class)->snapshot($this->tenant, 1);
    $values = array_column($metrics['metrics'], 'value', 'name');
    expect($values['proxyma_completed'])->toBe(1.0)->and($values['proxyma_unknown'])->toBe(0.0);
    foreach ($metrics['metrics'] as $sample) {
        expect(array_keys($sample['labels']))->toBe(['reason']);
    }
});

it('CLI sanitizes exception messages и проверяет permission metrics без утечки DSN', function (): void {
    $this->app->instance(Authorizer::class, new class implements Authorizer
    {
        public function authorize(ActorRef $actor, TenantKey $tenant, string $ability, Uuid $resourceId): void
        {
            throw new RuntimeException('pgsql://user:secret-password@private.test/?token=leak');
        }
    });
    foreach (['proxyma:metrics', 'proxyma:reconcile', 'proxyma:prune'] as $command) {
        $kernel = $this->app->make(Kernel::class);
        expect($kernel->call($command))->toBe(1);
        expect(trim($kernel->output()))->toBe('{"error":"operation_failed"}');
    }
    $this->permissions(fn ($actor, $tenant, $ability) => $ability !== 'diagnostics.metrics');
    expect($this->app->make(Kernel::class)->call('proxyma:metrics'))->toBe(1);
    expect($this->app->make(Kernel::class)->output())->toContain('permission_denied');
});
