<?php

declare(strict_types=1);

namespace Proxyma\Tests\Support;

use DateTimeImmutable;
use Proxyma\Application\Admission\DemandQueue;
use Proxyma\Application\Admission\OperationCommands;
use Proxyma\Contracts\WorkBudget;
use Proxyma\Domain\Admission\QueueLimits;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\EgressIdentity;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Execution\DemandPurpose;
use Proxyma\Domain\Execution\Duration;
use Proxyma\Domain\Execution\OperationCorrelation;
use Proxyma\Domain\Execution\OwnerRef;
use Proxyma\Domain\Execution\RequestTimeoutPolicy;
use Proxyma\Domain\Execution\RouteSelection;
use Proxyma\Domain\Execution\SafeUrl;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Policy\TenantKey;

final readonly class FixtureWorkBudget implements WorkBudget
{
    public function __construct(private bool $fits = true) {}

    public function canStart(Duration $required): bool
    {
        return $this->fits;
    }
}

trait DemandFixtures
{
    protected function initializeDemandFixture(): void
    {
        $this->catalog = $this->provision();
        $this->work = new FixtureWorkBudget;
        $this->queue = $this->app->make(DemandQueue::class);
        $this->operations = $this->app->make(OperationCommands::class);
        $this->scopeKey = CanonicalOrigin::of('https', 'example.test')->budgetScopeKey();
        $this->budget($this->scopeKey);
    }

    protected function budget(string $scope, ?TenantKey $tenant = null, int $inflight = 0): string
    {
        $id = Uuid::random()->value;
        $this->table('budgets')->insert(['id' => $id, 'tenant_key' => ($tenant ?? $this->tenant)->value, 'scope_key' => $scope,
            'emission_interval_us' => 1, 'burst' => 1, 'max_inflight' => 1, 'inflight' => $inflight]);

        return $id;
    }

    protected function demandOperation(?Uuid $target = null, string $url = 'https://example.test/path', ?Uuid $parent = null, DemandPurpose $purpose = DemandPurpose::Ordinary): array
    {
        $id = Uuid::random();
        $owner = new OwnerRef($this->tenant, 'standalone', $id->value, 'fixture-token');
        $operation = new OperationCorrelation($id, $owner, new DateTimeImmutable('+1 minute'), 'consumer:'.$id->value,
            purpose: $purpose, probeToken: $purpose === DemandPurpose::Probe ? Uuid::random() : null);
        $timeouts = new RequestTimeoutPolicy(1, 1, 1, 0, maxAttempts: 3, maxDistinctRoutes: 3);
        $target ??= $this->catalog['target'];
        $this->operations->create($operation, $this->catalog['profile'], $target, $timeouts, $parent);
        $request = new WireRequest($id, Uuid::random(), $target, 1, 'GET', new SafeUrl($url), $timeouts, $operation->deadlineAt, purpose: $purpose);
        $route = new RouteSelection($this->catalog['route'], RouteKind::Direct, $this->catalog['profile'], null, 1, EgressIdentity::explicit('nat:fixture'));

        return ['operation' => $operation, 'request' => $request, 'route' => $route, 'timeouts' => $timeouts];
    }

    protected function enqueueDemand(array $intent, array $scopes = [], ?DemandQueue $queue = null): mixed
    {
        return ($queue ?? $this->queue)->enqueue($intent['request'], $intent['operation'], $intent['route'], $this->work, $scopes);
    }

    protected function takeDemand($ticket, array $intent, array $scopes = [], ?DemandQueue $queue = null): mixed
    {
        return ($queue ?? $this->queue)->take($ticket, $intent['request'], $intent['operation'], $intent['route'], $this->work, $scopes);
    }

    protected function queueWithLimits(QueueLimits $limits): DemandQueue
    {
        $this->app->instance(QueueLimits::class, $limits);

        return $this->app->make(DemandQueue::class);
    }
}
