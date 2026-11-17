<?php

declare(strict_types=1);

use Proxyma\Application\Routing\RouteSelector;
use Proxyma\Application\Routing\StrategyRegistry;
use Proxyma\Contracts\Catalog\CandidateSnapshots;
use Proxyma\Contracts\Routing\RandomIndex;
use Proxyma\Contracts\Routing\RouteSelectionStrategy;
use Proxyma\Domain\Execution\RouteSelectionContext;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Routing\SelectionResult;
use Proxyma\Tests\Support\CatalogTestCase;

require_once __DIR__.'/../Support/CatalogTestCase.php';
require_once __DIR__.'/../Support/SelectionFixtures.php';

uses(CatalogTestCase::class)->group('pg');

final readonly class QueryBudgetRandom implements RandomIndex
{
    public function __construct(private int $index) {}

    public function next(int $upperExclusive): int
    {
        return $this->index;
    }
}

it('читает 1, 10 и 100 маршрутов одинаковым ограниченным числом SQL запросов', function (): void {
    $c = $this->provision();
    selectionHealth($this->db, $this->tenant);
    $query = $this->app->make(CandidateSnapshots::class);
    $policy = selectionPolicy($this->tenant, $c['profile'], $c['target']);
    $query->load($this->tenant, $c['profile'], $c['target'], $policy);
    $counts = [];
    foreach ([1 => 0, 10 => 9, 100 => 90] as $size => $additional) {
        selectionAddRoutes($this->db, $this->tenant, $c['profile'], $c['target'], $additional);
        $this->db->enableQueryLog();
        $this->db->flushQueryLog();
        $snapshot = $query->load($this->tenant, $c['profile'], $c['target'], $policy);
        $counts[$size] = count($this->db->getQueryLog());
        $this->db->disableQueryLog();
        expect($snapshot->candidates)->toHaveCount($size)
            ->and(array_filter($snapshot->candidates, fn ($candidate) => $candidate->eligible()))->toHaveCount($size)
            ->and($counts[$size])->toBeLessThanOrEqual(12);
    }
    fwrite(STDERR, 'Snapshot SQL counts: '.json_encode($counts, JSON_THROW_ON_ERROR).PHP_EOL);
    expect($counts[1])->toBe($counts[10])->toBe($counts[100]);
});

it('выбирает least-recent со стабильным UUID tie-break и random через injected RNG', function (): void {
    $c = $this->provision();
    selectionHealth($this->db, $this->tenant);
    $others = selectionAddRoutes($this->db, $this->tenant, $c['profile'], $c['target'], 2);
    $all = [$c['route']->value, $others[0]->value, $others[1]->value];
    sort($all, SORT_STRING);
    $query = $this->app->make(CandidateSnapshots::class);
    $request = selectionRequest($c['target']);
    $policy = selectionPolicy($this->tenant, $c['profile'], $c['target'], 'least_recent_authorized');
    $selector = new RouteSelector($query, new StrategyRegistry(new QueryBudgetRandom(0)));
    expect($selector->select($this->tenant, $c['profile'], $policy, $request, new RouteSelectionContext)->route->id->value)->toBe($all[0]);
    $random = new RouteSelector($query, new StrategyRegistry(new QueryBudgetRandom(2)));
    $randomPolicy = selectionPolicy($this->tenant, $c['profile'], $c['target']);
    expect($random->select($this->tenant, $c['profile'], $randomPolicy, $request, new RouteSelectionContext)->route->id->value)->toBe($all[2]);
    $bad = new RouteSelector($query, new StrategyRegistry(new QueryBudgetRandom(3)));
    expect(fn (): SelectionResult => $bad->select($this->tenant, $c['profile'], $randomPolicy, $request, new RouteSelectionContext))->toThrow(InvalidArgumentException::class, 'outside');
    expect(fn (): RouteSelectionStrategy => new StrategyRegistry(new QueryBudgetRandom(0))->get('App\\Injected'))->toThrow(InvalidArgumentException::class);
});

it('last authorization использует фактические permits, а не порядок membership', function (): void {
    $c = $this->provision();
    selectionHealth($this->db, $this->tenant);
    $second = selectionAddRoutes($this->db, $this->tenant, $c['profile'], $c['target'], 1)[0];
    $operation = $this->operation($c);
    $demand = Uuid::random();
    $this->table('demands')->insert(['id' => $demand->value, 'tenant_key' => $this->tenant->value,
        'operation_id' => $operation->value, 'profile_id' => $c['profile']->value, 'target_id' => $c['target']->value,
        'purpose' => 'normal', 'cost' => 1, 'deadline' => $this->db->raw("clock_timestamp() + interval '1 minute'")]);
    $this->table('permits')->insert(['tenant_key' => $this->tenant->value, 'operation_id' => $operation->value,
        'demand_id' => $demand->value, 'route_id' => $c['route']->value, 'target_id' => $c['target']->value,
        'physical_attempt_id' => Uuid::random()->value, 'wire_fingerprint' => str_repeat('a', 64), 'state' => 'authorized',
        'cost' => 1, 'not_before' => $this->db->raw("clock_timestamp() - interval '1 second'"),
        'expires_at' => $this->db->raw("clock_timestamp() + interval '1 minute'"), 'authorized_at' => $this->db->raw('clock_timestamp()'), 'claim_id' => Uuid::random()->value]);
    $policy = selectionPolicy($this->tenant, $c['profile'], $c['target'], 'least_recent_authorized');
    $selector = new RouteSelector($this->app->make(CandidateSnapshots::class), new StrategyRegistry(new QueryBudgetRandom(0)));
    $result = $selector->select($this->tenant, $c['profile'], $policy, selectionRequest($c['target']), new RouteSelectionContext);
    expect($result->route->id->value)->toBe($second->value)->and($result->snapshot->candidates[0]->lastAuthorizedAt)->not->toBeNull();
});
