<?php

declare(strict_types=1);

namespace Proxyma\Tests\Support;

use Closure;
use Proxyma\Application\Admission\ReservationService;
use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Application\Policy\SettingRegistry;
use Proxyma\Contracts\Admission\BudgetScopeContributor;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Panels\BudgetScopeSchema;
use Proxyma\Domain\Panels\ExtensionDefinition;
use Proxyma\Domain\Panels\ExtensionKind;

final readonly class FixtureBudgetContributor implements BudgetScopeContributor
{
    public function __construct(private array $constraints, private ?Closure $observe = null) {}

    public function contribute(WireRequest $request, Route $route, CanonicalOrigin $origin): array
    {
        if ($this->observe !== null) {
            ($this->observe)();
        }

        return $this->constraints;
    }
}

trait ReservationFixtures
{
    protected function initializeReservationFixture(): void
    {
        $this->initializeDemandFixture();
        \selectionHealth($this->db, $this->tenant);
        $origin = CanonicalOrigin::of('https', 'example.test');
        $this->budget($origin->routeScopeKey($this->catalog['route']));
        $this->budget($origin->egressScopeKey('nat:fixture'));
        $this->reservations = $this->app->make(ReservationService::class);
    }

    protected function reservationInput(array $intent, array $heads = []): mixed
    {
        $policy = \selectionPolicy($this->tenant, $this->catalog['profile'], $this->catalog['target'], layerRevisions: $heads,
            trafficKey: $intent['request']->trafficClass);

        return $this->reservations->prepare($intent['request'], $intent['operation'], $intent['route'], $policy, $this->work);
    }

    protected function contributor(array $constraints, ?Closure $observe = null): void
    {
        $registry = new DefinitionRegistry($this->app->make(SettingRegistry::class));
        $registry->registerExtension(new ExtensionDefinition('fixture.extra', ExtensionKind::BudgetScopeContributor, new FixtureBudgetContributor($constraints, $observe),
            budgetSchema: new BudgetScopeSchema('fixture.extra', keys: array_column($constraints, 'scopeKey'))));
        $registry->freeze();
        $this->app->instance(DefinitionRegistry::class, $registry);
        $this->reconfigureProxyma(['extensions.budget_contributors' => ['fixture.extra']]);
        $this->reservations = $this->app->make(ReservationService::class);
    }

    protected function accounting(): array
    {
        return array_map(static fn ($row): array => (array) $row, $this->table('budgets')->orderBy('scope_key')->orderBy('tenant_key')->select(['id', 'tat', 'send_tat', 'inflight'])->get()->all());
    }
}
