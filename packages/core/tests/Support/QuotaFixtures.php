<?php

declare(strict_types=1);

namespace Proxyma\Tests\Support;

require_once __DIR__.'/DestinationFixtures.php';

use Proxyma\Application\Admission\ClaimSend;
use Proxyma\Application\Admission\QuotaService;
use Proxyma\Application\Admission\ReservationService;
use Proxyma\Contracts\CatalogQueries;
use Proxyma\Domain\Execution\DemandPurpose;
use Proxyma\Domain\Execution\OperationCorrelation;
use Proxyma\Domain\Execution\OwnerRef;
use Proxyma\Domain\Execution\RequestTimeoutPolicy;
use Proxyma\Domain\Execution\ResolvedUrl;
use Proxyma\Domain\Execution\RouteSelection;
use Proxyma\Domain\Execution\SafeUrl;
use Proxyma\Domain\Execution\ScheduledPermit;
use Proxyma\Domain\Execution\TlsTrustConfiguration;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Infrastructure\Database\DatabaseClock;

trait QuotaFixtures
{
    protected function initializeQuotaFixture(): void
    {
        $this->initializeClaimFixture();
        $this->table('budgets')->update(['max_inflight' => 100, 'burst' => 100]);
        $this->quota = $this->app->make(QuotaService::class);
        $this->originBudget = new Uuid($this->table('budgets')->where('scope_key', $this->scopeKey)->value('id'));
        $this->workerClasses = [];
    }

    protected function sqlClock(string $instant): void
    {
        $this->db->statement('CREATE TABLE fixture_clock (instant timestamptz NOT NULL)');
        $this->db->table('fixture_clock')->insert(['instant' => $instant]);
        $this->db->unprepared("CREATE FUNCTION {$this->fixtureSchema}.clock_timestamp() RETURNS timestamptz LANGUAGE sql VOLATILE
            AS 'SELECT instant FROM {$this->fixtureSchema}.fixture_clock'");
        $this->db->statement('SET search_path TO '.$this->fixtureSchema.', pg_catalog');
    }

    protected function moveSqlClock(string $instant): void
    {
        $this->db->table('fixture_clock')->update(['instant' => $instant]);
    }

    protected function quotaIntent(string $class = 'default', DemandPurpose $purpose = DemandPurpose::Ordinary): array
    {
        $id = Uuid::random();
        $owner = new OwnerRef($this->tenant, 'standalone', $id->value, 'fixture-token');
        $operation = new OperationCorrelation($id, $owner, DatabaseClock::now($this->db)->modify('+1 minute'), 'quota:'.$id->value,
            $purpose, $purpose === DemandPurpose::Probe ? Uuid::random() : null);
        $timeouts = new RequestTimeoutPolicy(1, 1, 1, 0, maxAttempts: 8, maxDistinctRoutes: 8);
        $this->operations->create($operation, $this->catalog['profile'], $this->catalog['target'], $timeouts);
        $route = $this->app->make(CatalogQueries::class)->findRoute($this->tenant, $this->catalog['route']);
        $selection = new RouteSelection($route->id, $route->kind, $this->catalog['profile'], $route->endpointId, $route->credentialVersion, $route->egress);
        $request = new WireRequest($id, Uuid::random(), $this->catalog['target'], 1, 'GET', new SafeUrl('https://example.test/path'), $timeouts,
            $operation->deadlineAt, purpose: $purpose, trafficClass: $class);

        return ['operation' => $operation, 'request' => $request, 'route' => $selection];
    }

    protected function quotaPrepared(string $class = 'default', ?array $intent = null): array
    {
        [$ticket,$input,$wire] = $this->quotaEnqueued($class, $intent);
        $permit = $this->reservations->reserve($ticket, $input);
        if ($permit instanceof ScheduledPermit) {
            $this->claims->prepare($permit, $input, $wire);
        }

        return [$permit, $input, $wire];
    }

    protected function quotaEnqueued(string $class = 'default', ?array $intent = null): array
    {
        $intent ??= $this->quotaIntent($class);
        $policy = \selectionPolicy($this->tenant, $this->catalog['profile'], $this->catalog['target'], trafficKey: $class);
        $input = $this->reservations->prepare($intent['request'], $intent['operation'], $intent['route'], $policy, $this->work);
        $wire = $this->sealer->seal($input->request, $input->route, fixtureDestination($this->transactions->scope, $input->request), new TlsTrustConfiguration);
        $input = $this->reservations->prepare($wire->request, $intent['operation'], $intent['route'], $policy, $this->work);
        $ticket = $this->reservations->enqueue($input, $this->work);

        return [$ticket, $input, $wire];
    }

    protected function trafficShares(array $classes): void
    {
        $this->app['config']->set('proxyma.traffic_shares', $classes);
        $this->workerClasses = $classes;
        $this->reservations = $this->app->make(ReservationService::class);
        $this->quota = $this->app->make(QuotaService::class);
        $this->app->forgetInstance(ClaimSend::class);
        $this->claims = $this->app->make(ClaimSend::class);
    }

    protected function quotaCounts(): array
    {
        return array_map(static fn ($row) => (array) $row, $this->table('quota_windows')->orderBy('budget_id')->orderBy('window_kind')->orderBy('starts_at')
            ->select(['id', 'budget_id', 'window_kind', 'starts_at', 'ends_at', 'quota_limit', 'reserved', 'spent'])->get()->all());
    }
}
