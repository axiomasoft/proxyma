<?php

declare(strict_types=1);

namespace Proxyma\Laravel\Registrars;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Contracts\PackageRegistrar;
use Proxyma\Application\Admission\AuthorityLocker;
use Proxyma\Application\Admission\ClaimSend;
use Proxyma\Application\Admission\DemandQueue;
use Proxyma\Application\Admission\OperationCommands;
use Proxyma\Application\Admission\QuotaService;
use Proxyma\Application\Admission\ReservationService;
use Proxyma\Application\Admission\TrafficSharePolicy;
use Proxyma\Application\Admission\TransactionCoordinator;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Execution\OutcomePolicyRegistry;
use Proxyma\Application\Execution\WireSealer;
use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Application\Maintenance\MaintenanceSchedule;
use Proxyma\Application\Recovery\CompletionCoordinator;
use Proxyma\Application\Recovery\OwnedTemporaryArtifacts;
use Proxyma\Application\Recovery\Reconciler;
use Proxyma\Application\Recovery\RetentionPruner;
use Proxyma\Configuration\ProxymaOptions;
use Proxyma\Contracts\Admission\OwnerAuthority;
use Proxyma\Contracts\Admission\TransactionCoordinator as TransactionCoordinatorContract;
use Proxyma\Contracts\Catalog\CatalogQueries;
use Proxyma\Contracts\Execution\Completion;
use Proxyma\Contracts\Execution\LockedCompletionReducer;
use Proxyma\Domain\Admission\QueueLimits;
use Proxyma\Domain\Health\HealthReducer;
use Proxyma\Domain\Health\HealthThresholds;
use Proxyma\Infrastructure\Admission\DatabaseOwnerAuthority;
use Proxyma\Infrastructure\Admission\ReservationCatalogLocks;
use Proxyma\Infrastructure\Database\BudgetRepository;
use Proxyma\Infrastructure\Database\CompletionObservationReducer;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Database\DemandRepository;
use Proxyma\Infrastructure\Database\PermitRepository;
use Proxyma\Infrastructure\Database\QuotaRepository;
use Proxyma\Infrastructure\Database\ReconcileRepository;

/**
 * Registers Proxyma transaction coordination, admission locks, budgets, quotas, health reducers, and recovery workers.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class ProxymaAdmissionRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        $app->scoped(TransactionCoordinator::class);
        $app->alias(TransactionCoordinator::class, TransactionCoordinatorContract::class);
        $app->scoped(AuthorityLocker::class);

        $app->bind(DatabaseOwnerAuthority::class);
        $app->bindIf(OwnerAuthority::class, DatabaseOwnerAuthority::class);

        $app->bind(QueueLimits::class, static fn (Application $app): QueueLimits => $app->make(ProxymaOptions::class)->queue->toQueueLimits());
        $app->bind(DemandRepository::class);
        $app->bind(OperationCommands::class);
        $app->bind(DemandQueue::class);
        $app->bind(BudgetRepository::class);
        $app->bind(PermitRepository::class);
        $app->bind(TrafficSharePolicy::class, static fn (Application $app): TrafficSharePolicy => new TrafficSharePolicy(
            $app->make(ProxymaOptions::class)->trafficShares,
        ));
        $app->bind(QuotaRepository::class);
        $app->bind(QuotaService::class);
        $app->bind(ReconcileRepository::class);
        $app->bind(HealthThresholds::class, static fn (Application $app): HealthThresholds => $app->make(ProxymaOptions::class)->health->toHealthThresholds());
        $app->bind(HealthReducer::class);
        $app->bind(LockedCompletionReducer::class, CompletionObservationReducer::class);
        $app->bind(CompletionCoordinator::class);
        $app->alias(CompletionCoordinator::class, Completion::class);
        $app->bind(Reconciler::class);
        $app->bind(RetentionPruner::class);
        $app->bind(MaintenanceSchedule::class, static fn (Application $app): MaintenanceSchedule => new MaintenanceSchedule(
            $app->make(ProxymaOptions::class),
        ));
        $app->bind(OwnedTemporaryArtifacts::class, static fn (Application $app): OwnedTemporaryArtifacts => new OwnedTemporaryArtifacts(
            $app->make(TransactionCoordinator::class),
            $app->make(ReconcileRepository::class),
            $app->make(CatalogAccess::class),
            $app->storagePath('app/proxyma/tmp'),
        ));
        $app->bind(ReservationCatalogLocks::class);

        $app->scoped(WireSealer::class, static fn (Application $app): WireSealer => new WireSealer(
            $app->make(ConnectionScope::class),
            $app->make(ProxymaOptions::class)->wireDigestKey->reveal(),
        ));

        $app->scoped(ClaimSend::class, static function (Application $app): ClaimSend {
            $options = $app->make(ProxymaOptions::class);

            return new ClaimSend(
                $app->make(TransactionCoordinator::class),
                $app->make(AuthorityLocker::class),
                $app->make(OwnerAuthority::class),
                $app->make(ReservationCatalogLocks::class),
                $app->make(DemandRepository::class),
                $app->make(BudgetRepository::class),
                $app->make(PermitRepository::class),
                $app->make(DefinitionRegistry::class),
                $app->make(WireSealer::class),
                $options->extensions->lockedAdmissionGuards,
                $app->make(QuotaService::class),
                $app->make(QuotaRepository::class),
                $options->healthCooldownScopes,
                $app->make(OutcomePolicyRegistry::class),
                $options->extensions->budgetContributors,
            );
        });

        $app->bind(ReservationService::class, static function (Application $app): ReservationService {
            $options = $app->make(ProxymaOptions::class);

            return new ReservationService(
                $app->make(TransactionCoordinator::class),
                $app->make(AuthorityLocker::class),
                $app->make(CatalogAccess::class),
                $app->make(CatalogQueries::class),
                $app->make(OwnerAuthority::class),
                $app->make(DefinitionRegistry::class),
                $app->make(DemandQueue::class),
                $app->make(DemandRepository::class),
                $app->make(BudgetRepository::class),
                $app->make(PermitRepository::class),
                $app->make(ReservationCatalogLocks::class),
                $app->make(QueueLimits::class),
                $options->extensions->budgetContributors,
                $options->extensions->lockedAdmissionGuards,
                quotaRepository: $app->make(QuotaRepository::class),
                traffic: $app->make(TrafficSharePolicy::class),
            );
        });
    }

    #[Override]
    public function boot(Application $app): void
    {
        if (! $app->runningInConsole()) {
            return;
        }

        $app->afterResolving(Schedule::class, static function (Schedule $schedule) use ($app): void {
            $app->make(MaintenanceSchedule::class)->register($schedule);
        });
    }
}
