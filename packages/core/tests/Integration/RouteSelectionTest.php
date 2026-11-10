<?php

declare(strict_types=1);

use Proxyma\Application\Admission\QuotaService;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Routing\RouteSelector;
use Proxyma\Application\Routing\StrategyRegistry;
use Proxyma\Contracts\CandidateSnapshots;
use Proxyma\Contracts\RandomIndex;
use Proxyma\Contracts\RouteSelectionStrategy;
use Proxyma\Contracts\SharedRouteCommands;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\EgressIdentity;
use Proxyma\Domain\Catalog\ProxyProtocol;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Catalog\ShareRouteCommand;
use Proxyma\Domain\Catalog\UpsertEndpointCommand;
use Proxyma\Domain\Catalog\UpsertRouteCommand;
use Proxyma\Domain\Catalog\UpsertRouteProfileCommand;
use Proxyma\Domain\Catalog\UpsertTargetCommand;
use Proxyma\Domain\Execution\RouteSelectionContext;
use Proxyma\Domain\Execution\SafeUrl;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Policy\BudgetConstraint;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Tests\Support\CatalogTestCase;

require_once __DIR__.'/../Support/CatalogTestCase.php';
require_once __DIR__.'/../Support/SelectionFixtures.php';

uses(CatalogTestCase::class);

final readonly class FixtureRandom implements RandomIndex
{
    public function __construct(private int $value) {}

    public function next(int $upperExclusive): int
    {
        return $this->value;
    }
}

beforeEach(function (): void {
    $this->catalog = $this->provision();
    selectionHealth($this->db, $this->tenant);
    $this->snapshotQuery = $this->app->make(CandidateSnapshots::class);
    $this->selector = new RouteSelector($this->snapshotQuery, new StrategyRegistry(new FixtureRandom(0)));
    $this->request = selectionRequest($this->catalog['target']);
    $this->policy = selectionPolicy($this->tenant, $this->catalog['profile'], $this->catalog['target']);
});

it('запрещает пустой профиль и direct без явного разрешения', function (): void {
    $denied = selectionPolicy($this->tenant, $this->catalog['profile'], $this->catalog['target'], allowDirect: false);
    $result = $this->selector->select($this->tenant, $this->catalog['profile'], $denied, $this->request, new RouteSelectionContext);
    expect($result->route)->toBeNull()->and($result->snapshot->candidates[0]->exclusions)->toContain('direct_not_allowed');
    $allowed = $this->selector->select($this->tenant, $this->catalog['profile'], $this->policy, $this->request, new RouteSelectionContext);
    expect($allowed->route->id->equals($this->catalog['route']))->toBeTrue();
    $this->commands()->upsertProfile(new UpsertRouteProfileCommand($this->catalog['profile'], $this->tenant, 'default', [], 'uniform_random', $this->actor(1)));
    $empty = $this->selector->select($this->tenant, $this->catalog['profile'], $this->policy, $this->request, new RouteSelectionContext);
    expect($empty->route)->toBeNull()->and($empty->snapshot->holds)->toContain('empty_profile');
});

it('объясняет отказ для каждого кандидата при несовпадении origin запроса', function (): void {
    $second = selectionAddRoutes($this->db, $this->tenant, $this->catalog['profile'], $this->catalog['target'], 1)[0];
    $request = new WireRequest(Uuid::random(), Uuid::random(), $this->catalog['target'], 1, 'GET', new SafeUrl('https://other.test/path'),
        $this->request->timeouts, new DateTimeImmutable('+1 hour'));
    $result = $this->selector->select($this->tenant, $this->catalog['profile'], $this->policy, $request, new RouteSelectionContext);
    expect($result->route)->toBeNull()->and($result->reason)->toBe('configuration_hold')
        ->and($result->selectionExclusions)->toMatchArray([
            $this->catalog['route']->value => 'request_origin_mismatch',
            $second->value => 'request_origin_mismatch',
        ]);
});

it('использует только точный tenant и membership даже при retry с чужим route', function (): void {
    $other = selectionAddRoutes($this->db, $this->tenant, $this->catalog['profile'], $this->catalog['target'], 1)[0];
    $this->table('route_profile_members')->where('route_id', $other->value)->delete();
    $retry = new RouteSelectionContext([$other], $other, false, 3);
    $result = $this->selector->select($this->tenant, $this->catalog['profile'], $this->policy, $this->request, $retry);
    expect($result->route->id->value)->toBe($this->catalog['route']->value)->and($result->snapshot->candidates)->toHaveCount(1);
    $otherTenant = new TenantKey('other');
    expect(fn () => $this->selector->select($otherTenant, $this->catalog['profile'], $this->policy, $this->request, $retry))->toThrow(InvalidArgumentException::class);
    $this->useTenant($otherTenant);
    expect(fn () => $this->selector->select($this->tenant, $this->catalog['profile'], $this->policy, $this->request, $retry))->toThrow(AuthorizationDenied::class);
});

it('отклоняет missing health, unknown health, retired target и неподходящую policy', function (): void {
    $route = $this->catalog['route']->value;
    $this->table('route_target_health')->where('route_id', $route)->delete();
    $result = $this->selector->select($this->tenant, $this->catalog['profile'], $this->policy, $this->request, new RouteSelectionContext);
    expect($result->route)->toBeNull()->and($result->snapshot->candidates[0]->exclusions)->toContain('route_health_unavailable');
    $this->table('target_health')->update(['state' => 'unknown']);
    $result = $this->selector->select($this->tenant, $this->catalog['profile'], $this->policy, $this->request, new RouteSelectionContext);
    expect($result->snapshot->holds)->toContain('target_health_unavailable')->and($result->route)->toBeNull();
    $missingProfile = $this->selector->select($this->tenant, Uuid::random(), $this->policy, $this->request, new RouteSelectionContext);
    expect($missingProfile->route)->toBeNull()->and($missingProfile->snapshot->holds)->toContain('profile_missing_or_retired');
    $this->table('targets')->where('id', $this->catalog['target']->value)->update(['retired_at' => $this->db->raw('clock_timestamp()'), 'revision' => 2]);
    $retired = $this->selector->select($this->tenant, $this->catalog['profile'], $this->policy, $this->request, new RouteSelectionContext);
    expect($retired->route)->toBeNull()->and($retired->snapshot->holds)->toContain('target_missing_or_retired');
});

it('применяет policy deny, права и retry только как сужение профиля', function (): void {
    $second = selectionAddRoutes($this->db, $this->tenant, $this->catalog['profile'], $this->catalog['target'], 1)[0];
    $first = $this->catalog['route'];
    $denied = selectionPolicy($this->tenant, $this->catalog['profile'], $this->catalog['target'], denied: [$first->value]);
    $result = $this->selector->select($this->tenant, $this->catalog['profile'], $denied, $this->request, new RouteSelectionContext);
    expect($result->route->id->value)->toBe($second->value)->and($result->snapshot->candidates[0]->exclusions)->toContain('route_denied_by_policy');
    $result = $this->selector->select($this->tenant, $this->catalog['profile'], $this->policy, $this->request, new RouteSelectionContext([$first], $first, true, 2));
    expect($result->route->id->value)->toBe($second->value)->and($result->selectionExclusions[$first->value])->toBe('prefer_untried');
    $result = $this->selector->select($this->tenant, $this->catalog['profile'], $this->policy, $this->request, new RouteSelectionContext([$first], $second, false, 1));
    expect($result->route->id->value)->toBe($first->value)->and($result->selectionExclusions[$second->value])->toBe('distinct_route_limit');
    $this->permissions(fn ($actor, $tenant, string $ability, Uuid $id): bool => $ability !== 'catalog.route.select' || ! $id->equals($first));
    $result = $this->selector->select($this->tenant, $this->catalog['profile'], $this->policy, $this->request, new RouteSelectionContext);
    expect($result->route->id->value)->toBe($second->value)->and($result->snapshot->candidates[0]->exclusions)->toContain('route_permission_denied');
    expect($result->snapshot->candidates[0]->route)->toBeNull();
});

it('не выбирает proxy с отозванным endpoint, неизвестным протоколом или strategy', function (): void {
    $endpoint = Uuid::random();
    $proxy = Uuid::random();
    $commands = $this->commands();
    $commands->upsertEndpoint(new UpsertEndpointCommand($endpoint, $this->tenant, ProxyProtocol::Http, 'proxy.test', 8080, null, $this->actor()));
    $commands->upsertRoute(new UpsertRouteCommand($proxy, $this->tenant, 'proxy', RouteKind::Proxy, $endpoint, true, EgressIdentity::explicit('nat:proxy'), null, false, $this->actor()));
    $commands->upsertProfile(new UpsertRouteProfileCommand($this->catalog['profile'], $this->tenant, 'default', [$proxy], 'uniform_random', $this->actor(1)));
    selectionHealth($this->db, $this->tenant);
    $this->db->statement('ALTER TABLE pxm_endpoints DROP CONSTRAINT pxm_endpoints_protocol_check');
    $this->table('endpoints')->where('id', $endpoint->value)->update(['protocol' => 'unknown', 'revision' => 2]);
    $result = $this->selector->select($this->tenant, $this->catalog['profile'], $this->policy, $this->request, new RouteSelectionContext);
    expect($result->route)->toBeNull()->and($result->snapshot->candidates[0]->exclusions)->toContain('route_or_endpoint_configuration_invalid');
    $this->table('endpoints')->where('id', $endpoint->value)->update(['protocol' => 'http', 'retired_at' => $this->db->raw('clock_timestamp()'), 'revision' => 3]);
    $result = $this->selector->select($this->tenant, $this->catalog['profile'], $this->policy, $this->request, new RouteSelectionContext);
    expect($result->route)->toBeNull()->and($result->snapshot->candidates[0]->exclusions)->toContain('route_or_endpoint_configuration_invalid');
    $this->table('route_profiles')->where('id', $this->catalog['profile']->value)->update(['strategy_key' => 'unknown', 'revision' => 3]);
    $result = $this->selector->select($this->tenant, $this->catalog['profile'], $this->policy, $this->request, new RouteSelectionContext);
    expect($result->route)->toBeNull()->and($result->snapshot->holds)->toContain('unknown_profile_strategy');
});

it('останавливает вызов в открытой transaction до обращения к authorizer', function (): void {
    $this->permissions(fn (): bool => throw new LogicException('Authorizer must not run in a transaction'));
    expect(fn () => $this->db->transaction(fn () => $this->selector->select($this->tenant, $this->catalog['profile'], $this->policy, $this->request, new RouteSelectionContext)))
        ->toThrow(InvalidArgumentException::class, 'before opening');
});

it('не расширяет общую origin quota и группирует неизвестные выходы по pool', function (): void {
    $ids = selectionAddRoutes($this->db, $this->tenant, $this->catalog['profile'], $this->catalog['target'], 2);
    $origin = CanonicalOrigin::of('https', 'example.test');
    $originKey = $origin->budgetScopeKey();
    $egressKey = $origin->egressScopeKey('pool:shared');
    $budgetId = Uuid::random();
    $this->table('budgets')->insert(['id' => $budgetId->value, 'tenant_key' => $this->tenant->value, 'scope_key' => $originKey, 'emission_interval_us' => 1, 'burst' => 1, 'max_inflight' => 5]);
    $this->table('budgets')->insert(['tenant_key' => $this->tenant->value, 'scope_key' => $egressKey, 'emission_interval_us' => 1, 'burst' => 1, 'max_inflight' => 5]);
    $this->app->make(QuotaService::class)->configure($this->tenant, $budgetId, 1, null, 1);
    $this->table('quota_windows')->where('budget_id', $budgetId->value)->where('window_kind', 'hour')
        ->whereRaw('starts_at <= clock_timestamp() AND ends_at > clock_timestamp()')->update(['spent' => 1]);
    $result = $this->selector->select($this->tenant, $this->catalog['profile'], $this->policy, $this->request, new RouteSelectionContext);
    expect($result->route)->toBeNull();
    foreach ($result->snapshot->candidates as $candidate) {
        expect($candidate->exclusions)->toContain('quota_exhausted')->and($candidate->budgetScopes)->toContain($originKey);
        if (in_array($candidate->routeId->value, array_map(fn ($id) => $id->value, $ids), true)) {
            expect($candidate->budgetScopes)->toContain($egressKey)->and($candidate->route->egress->conservativePoolFallback)->toBeTrue();
        }
    }
    $missing = selectionPolicy($this->tenant, $this->catalog['profile'], $this->catalog['target'], budgets: [new BudgetConstraint('tenant:required', 1)]);
    $result = $this->selector->select($this->tenant, $this->catalog['profile'], $missing, $this->request, new RouteSelectionContext);
    expect($result->snapshot->candidates[0]->exclusions)->toContain('required_budget_missing');
});

it('удерживает разрешённый cross-tenant grant без материализованной route-target health', function (): void {
    $consumer = new TenantKey('consumer');
    $grantId = Uuid::random();
    $this->app->make(SharedRouteCommands::class)->shareRoute(new ShareRouteCommand($grantId, $this->tenant, $consumer, $this->catalog['route'], $this->actor()));
    $this->useTenant($consumer);
    $profile = Uuid::random();
    $target = Uuid::random();
    $commands = $this->commands();
    $commands->upsertTarget(new UpsertTargetCommand($target, $consumer, 'consumer-target', CanonicalOrigin::of('https', 'example.test'), 'default', $this->actor()));
    $commands->upsertProfile(new UpsertRouteProfileCommand($profile, $consumer, 'default', [$this->catalog['route']], 'uniform_random', $this->actor()));
    selectionHealth($this->db, $consumer);
    $this->rejectedSql(fn () => $this->table('route_target_health')->insert(['tenant_key' => $consumer->value,
        'route_id' => $this->catalog['route']->value, 'target_id' => $target->value, 'state' => 'closed']), '23503');
    $policy = selectionPolicy($consumer, $profile, $target);
    $result = $this->selector->select($consumer, $profile, $policy, selectionRequest($target), new RouteSelectionContext);
    expect($result->route)->toBeNull()->and($result->snapshot->candidates[0]->exclusions)->toContain('route_health_unavailable')
        ->and($result->snapshot->candidates[0]->route->accessGrant->id->value)->toBe($grantId->value);
});

it('предпочитает готовый budget и сохраняет версии без выдачи permit', function (): void {
    $second = selectionAddRoutes($this->db, $this->tenant, $this->catalog['profile'], $this->catalog['target'], 1)[0];
    $origin = CanonicalOrigin::of('https', 'example.test');
    $key = $origin->routeScopeKey($this->catalog['route']);
    $this->table('budgets')->insert(['tenant_key' => $this->tenant->value, 'scope_key' => $key, 'emission_interval_us' => 1, 'burst' => 1, 'max_inflight' => 1, 'inflight' => 1]);
    $policy = selectionPolicy($this->tenant, $this->catalog['profile'], $this->catalog['target'], 'budget_aware_random');
    $result = $this->selector->select($this->tenant, $this->catalog['profile'], $policy, $this->request, new RouteSelectionContext);
    expect($result->route->id->value)->toBe($second->value)->and($result->snapshot->candidates[0]->budgetReady)->toBeFalse()
        ->and($result->selectionExclusions[$this->catalog['route']->value])->toBe('budget_not_ready')
        ->and($result->snapshot->candidates[0]->budgetRevisions[$this->tenant->value.':'.$key])->toBe(1)
        ->and($result->snapshot->candidates[0]->healthEpochs)->toHaveKeys(['profile', 'route', 'target', 'route_target'])
        ->and($this->table('permits')->count())->toBe(0);
});

it('отклоняет выбор strategy вне approved snapshot даже при поддельном route с тем же ID', function (): void {
    $outside = selectionAddRoutes($this->db, $this->tenant, $this->catalog['profile'], $this->catalog['target'], 1)[0];
    $captured = $this->snapshotQuery->load($this->tenant, $this->catalog['profile'], $this->catalog['target'], $this->policy)->candidates[1]->route;
    $this->table('route_profile_members')->where('route_id', $outside->value)->delete();
    $evil = new class($captured) implements RouteSelectionStrategy
    {
        public function __construct(private Route $route) {}

        public function key(): string
        {
            return 'uniform_random';
        }

        public function select(array $candidates, WireRequest $request, RouteSelectionContext $context): ?Route
        {
            return $this->route;
        }
    };
    $selector = new RouteSelector($this->snapshotQuery, new StrategyRegistry(new FixtureRandom(0), [$evil]));
    expect(fn () => $selector->select($this->tenant, $this->catalog['profile'], $this->policy, $this->request, new RouteSelectionContext))->toThrow(InvalidArgumentException::class, 'outside');
});

it('показывает что отключение или удаление выбранного route требует повторной authoritative проверки', function (): void {
    $choice = $this->selector->select($this->tenant, $this->catalog['profile'], $this->policy, $this->request, new RouteSelectionContext);
    $guard = function ($selected): bool {
        return $this->table('routes')->where('id', $selected->route->id->value)->where('enabled', true)->where('revision', $selected->route->revision)->exists()
            && $this->table('route_profile_members')->where('tenant_key', $this->tenant->value)->where('profile_id', $selected->selection->profileId->value)->where('route_id', $selected->route->id->value)->exists();
    };
    expect($guard($choice))->toBeTrue();
    $otherConnection = $this->connectionPdo();
    $disable = $otherConnection->prepare('UPDATE pxm_routes SET enabled = false, revision = revision + 1 WHERE tenant_key = ? AND id = ?');
    $disable->execute([$this->tenant->value, $this->catalog['route']->value]);
    expect($guard($choice))->toBeFalse();
    $enable = $otherConnection->prepare('UPDATE pxm_routes SET enabled = true, revision = revision + 1 WHERE tenant_key = ? AND id = ?');
    $enable->execute([$this->tenant->value, $this->catalog['route']->value]);
    $choice = $this->selector->select($this->tenant, $this->catalog['profile'], $this->policy, $this->request, new RouteSelectionContext);
    expect($guard($choice))->toBeTrue();
    $remove = $otherConnection->prepare('DELETE FROM pxm_route_profile_members WHERE tenant_key = ? AND profile_id = ? AND route_id = ?');
    $remove->execute([$this->tenant->value, $this->catalog['profile']->value, $this->catalog['route']->value]);
    expect($guard($choice))->toBeFalse();
});
