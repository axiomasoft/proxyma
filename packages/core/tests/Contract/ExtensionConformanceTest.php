<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Proxyma\Application\Admission\AuthorityLocker;
use Proxyma\Application\Admission\ClaimSend;
use Proxyma\Application\Admission\ReservationService;
use Proxyma\Application\Execution\DestinationPolicy;
use Proxyma\Application\Execution\OutboundExecutor;
use Proxyma\Application\Extensions\AttemptObservers;
use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Application\Extensions\ExtensionConfiguration;
use Proxyma\Application\Extensions\ExtensionRuntime;
use Proxyma\Application\Policy\SettingRegistry;
use Proxyma\Application\Routing\RouteSelector;
use Proxyma\Application\Routing\StrategyRegistry;
use Proxyma\Contracts\Admission\LockedAdmissionGuard;
use Proxyma\Contracts\Admission\SqlConnectionParticipant;
use Proxyma\Contracts\Bindings\SubjectResolver;
use Proxyma\Contracts\Catalog\CandidateSnapshots;
use Proxyma\Contracts\Catalog\CatalogQueries;
use Proxyma\Contracts\Execution\OutboundObserver;
use Proxyma\Contracts\Execution\PhysicalTransport;
use Proxyma\Contracts\Policy\PolicyDefinitionProvider;
use Proxyma\Contracts\Routing\RandomIndex;
use Proxyma\Contracts\Routing\RouteFilter;
use Proxyma\Contracts\Routing\RouteSelectionStrategy;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Admission\AuthorityLockTier;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Execution\AdmissionLockTier;
use Proxyma\Domain\Execution\AuthorizedWireRequest;
use Proxyma\Domain\Execution\ClaimDenialReason;
use Proxyma\Domain\Execution\ClaimDenied;
use Proxyma\Domain\Execution\Duration;
use Proxyma\Domain\Execution\ExecutionContext;
use Proxyma\Domain\Execution\ExecutionSucceeded;
use Proxyma\Domain\Execution\LockedOwnerState;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\OperationCorrelation;
use Proxyma\Domain\Execution\OutboundRequest;
use Proxyma\Domain\Execution\OwnerRef;
use Proxyma\Domain\Execution\ReadOnlyAttemptObservation;
use Proxyma\Domain\Execution\RequestTimeoutPolicy;
use Proxyma\Domain\Execution\RouteSelectionContext;
use Proxyma\Domain\Execution\SafeUrl;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Panels\BudgetScopeSchema;
use Proxyma\Domain\Panels\DefinitionError;
use Proxyma\Domain\Panels\ExtensionDefinition;
use Proxyma\Domain\Panels\ExtensionKind;
use Proxyma\Domain\Panels\PanelSlot;
use Proxyma\Domain\Panels\SubjectResolverDefinition;
use Proxyma\Domain\Policy\BudgetConstraint;
use Proxyma\Domain\Policy\BudgetConstraintSet;
use Proxyma\Domain\Policy\PanelDefinition;
use Proxyma\Domain\Policy\PolicyDefinition;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Domain\Routing\SelectionResult;
use Proxyma\Infrastructure\Http\DnsResolver;
use Proxyma\Tests\Support\CatalogTestCase;
use Proxyma\Tests\Support\ClaimFixtures;
use Proxyma\Tests\Support\DemandFixtures;
use Proxyma\Tests\Support\FixtureBudgetContributor;
use Proxyma\Tests\Support\FixturePhysicalTransport;
use Proxyma\Tests\Support\ReservationFixtures;
use Psr\Log\AbstractLogger;

foreach (['CatalogTestCase', 'SelectionFixtures', 'DemandFixtures', 'ReservationFixtures', 'ClaimFixtures'] as $file) {
    require_once __DIR__.'/../Support/'.$file.'.php';
}

uses(CatalogTestCase::class, DemandFixtures::class, ReservationFixtures::class, ClaimFixtures::class)->group('pg');

final class ConformanceFilter implements RouteFilter
{
    public function __construct(private ?Route $addition = null, private bool $drop = false) {}

    public function filter(array $profileRoutes, WireRequest $request): array
    {
        return $this->addition === null ? ($this->drop ? [] : $profileRoutes) : [...$profileRoutes, $this->addition];
    }
}

final readonly class ConformanceGuard implements LockedAdmissionGuard, SqlConnectionParticipant
{
    public function __construct(private Connection $db, private AuthorityLocker $locks, private string $attack = '',
        private AdmissionLockTier $declared = AdmissionLockTier::AdapterGuard) {}

    public function connection(): Connection
    {
        return $this->db;
    }

    public function tier(): AdmissionLockTier
    {
        return $this->declared;
    }

    public function evaluateLocked(WireRequest $request, LockedOwnerState $owner, LockedTransactionContext $transaction): ?ClaimDenied
    {
        if ($this->attack === 'owner_lock') {
            $this->locks->enter(AuthorityLockTier::Owner, $owner->owner->ownerId, $transaction);
        }
        if ($this->attack === 'wire') {
            $request->method = 'DELETE';
        }

        return $this->attack === 'deny' ? new ClaimDenied(ClaimDenialReason::GuardDenied) : null;
    }
}

function conformanceRegistry(object $test, array $definitions): DefinitionRegistry
{
    return (function () use ($definitions): DefinitionRegistry {
        $registry = new DefinitionRegistry($this->app->make(SettingRegistry::class));
        foreach ($definitions as $definition) {
            $registry->registerExtension($definition);
        }
        $registry->freeze();

        return $registry;
    })->call($test);
}

beforeEach(function (): void {
    $this->initializeClaimFixture();
});

it('замораживает реестр детерминированно без SQL и отклоняет позднюю регистрацию', function (): void {
    $this->db->enableQueryLog();
    $this->db->flushQueryLog();
    $a = new ExtensionDefinition('a', ExtensionKind::RouteFilter, new ConformanceFilter);
    $b = new ExtensionDefinition('b', ExtensionKind::RouteFilter, new ConformanceFilter, dependencies: ['a'], priority: 1);
    $first = conformanceRegistry($this, [$a, $b]);
    $second = conformanceRegistry($this, [$b, $a]);
    expect($first->fingerprint())->toBe($second->fingerprint())
        ->and(array_column($first->selectExtensions(ExtensionKind::RouteFilter, ['b', 'a']), 'id'))->toBe(['a', 'b'])
        ->and($this->db->getQueryLog())->toBe([]);
    expect(fn () => $first->registerCapability('late'))->toThrow(DefinitionError::class);
    expect(fn () => $first->registerExtension($a))->toThrow(DefinitionError::class);
    expect(fn (): array => $first->selectExtensions(ExtensionKind::RouteFilter, ['b']))->toThrow(DefinitionError::class);
    $changed = conformanceRegistry($this, [new ExtensionDefinition('a', ExtensionKind::RouteFilter, new ConformanceFilter, version: 2), $b]);
    expect($changed->fingerprint())->not->toBe($first->fingerprint());
});

it('отклоняет duplicate, missing, cycle, capability и priority conflicts', function (string $failure): void {
    $registry = new DefinitionRegistry($this->app->make(SettingRegistry::class));
    $filter = fn ($id, $deps = [], $priority = 0, $caps = []): ExtensionDefinition => new ExtensionDefinition($id, ExtensionKind::RouteFilter,
        new ConformanceFilter, dependencies: $deps, priority: $priority, requiredCapabilities: $caps);
    $definitions = match ($failure) {
        'duplicate' => [$filter('a'), $filter('a')],
        'missing' => [$filter('a', ['missing'])],
        'cycle' => [$filter('a', ['b']), $filter('b', ['a'], 1)],
        'priority' => [$filter('a'), $filter('b')],
        'order' => [$filter('a', ['b']), $filter('b', [], 1)],
        'capability' => [$filter('a', caps: ['missing'])],
    };
    expect(function () use ($registry, $definitions): void {
        foreach ($definitions as $definition) {
            $registry->registerExtension($definition);
        }
        $registry->freeze();
    })->toThrow(DefinitionError::class);
    expect(fn (): string => $registry->fingerprint())->toThrow(DefinitionError::class);
})->with(['duplicate', 'missing', 'cycle', 'priority', 'order', 'capability']);

it('отказывает panel с missing resolver или capability', function (bool $resolver): void {
    $registry = new DefinitionRegistry($this->app->make(SettingRegistry::class));
    if ($resolver) {
        $registry->registerSubjectResolver(new SubjectResolverDefinition('subjects', new class implements SubjectResolver
        {
            public function resolve(SubjectRef $leaf): SubjectChain
            {
                return new SubjectChain($leaf);
            }
        }, ['document']));
    }
    $registry->registerPanel(new PanelDefinition('files', 'subjects', [new PanelSlot('read', [])], ['absent']));
    expect(fn (): string => $registry->freeze())->toThrow(DefinitionError::class);
})->with([false, true]);

it('отклоняет несерилизуемый config, произвольный FQCN и missing selection', function (string $failure): void {
    $registry = new DefinitionRegistry($this->app->make(SettingRegistry::class));
    $configuration = new ExtensionConfiguration($this->app);
    $config = match ($failure) {
        'closure' => ['observers' => [static fn (): null => null]],
        'class' => ['providers' => ['App\\Untrusted']],
        'binding' => ['approved_providers' => ['bad' => stdClass::class]],
        'selection' => ['route_filters' => ['missing']],
    };
    expect(function () use ($configuration, $registry, $config): void {
        $configuration->register($registry, $config);
        $registry->freeze();
        $configuration->validate($registry, $config);
    })->toThrow(DefinitionError::class);
})->with(['closure', 'class', 'binding', 'selection']);

it('RouteFilter не добавляет чужого кандидата и умеет полностью запретить выбор', function (): void {
    $extra = selectionAddRoutes($this->db, $this->tenant, $this->catalog['profile'], $this->catalog['target'], 1)[0];
    $foreign = $this->app->make(CatalogQueries::class)->findRoute($this->tenant, $extra);
    $this->table('route_profile_members')->where('route_id', $extra->value)->delete();
    $registry = conformanceRegistry($this, [new ExtensionDefinition('add', ExtensionKind::RouteFilter, new ConformanceFilter($foreign))]);
    $selector = new RouteSelector($this->app->make(CandidateSnapshots::class), $this->app->make(StrategyRegistry::class), new ExtensionRuntime($registry), ['add']);
    $request = selectionRequest($this->catalog['target']);
    $policy = selectionPolicy($this->tenant, $this->catalog['profile'], $this->catalog['target']);
    expect(fn (): SelectionResult => $selector->select($this->tenant, $this->catalog['profile'], $policy, $request, new RouteSelectionContext))
        ->toThrow(InvalidArgumentException::class, 'outside');
    $registry = conformanceRegistry($this, [new ExtensionDefinition('drop', ExtensionKind::RouteFilter, new ConformanceFilter(drop: true))]);
    $selector = new RouteSelector($this->app->make(CandidateSnapshots::class), $this->app->make(StrategyRegistry::class), new ExtensionRuntime($registry), ['drop']);
    expect($selector->select($this->tenant, $this->catalog['profile'], $policy, $request, new RouteSelectionContext)->selection)->toBeNull();
    expect($this->table('permits')->count())->toBe(0);
});

it('strategy не получает permit и её foreign selection отвергается', function (): void {
    $foreignId = selectionAddRoutes($this->db, $this->tenant, $this->catalog['profile'], $this->catalog['target'], 1)[0];
    $route = $this->app->make(CatalogQueries::class)->findRoute($this->tenant, $foreignId);
    $this->table('route_profile_members')->where('route_id', $foreignId->value)->delete();
    $strategy = new class($route) implements RouteSelectionStrategy
    {
        public function __construct(private Route $route) {}

        public function key(): string
        {
            return 'malicious';
        }

        public function select(array $candidates, WireRequest $request, RouteSelectionContext $context): Route
        {
            return $this->route;
        }
    };
    $registry = new StrategyRegistry($this->app->make(RandomIndex::class), [$strategy]);
    $selector = new RouteSelector($this->app->make(CandidateSnapshots::class), $registry);
    expect(fn (): SelectionResult => $selector->select($this->tenant, $this->catalog['profile'],
        selectionPolicy($this->tenant, $this->catalog['profile'], $this->catalog['target'], 'malicious'),
        selectionRequest($this->catalog['target']), new RouteSelectionContext))->toThrow(InvalidArgumentException::class, 'outside');
    expect(new ReflectionClass(AuthorizedWireRequest::class)->getConstructor()->isPrivate())->toBeTrue()
        ->and($this->table('permits')->count())->toBe(0);
});

it('contributor не удаляет origin и не ослабляет его limits', function (): void {
    $intent = $this->demandOperation();
    $origin = new BudgetConstraint($this->scopeKey, 100, 1, 1, true, 2, 3);
    $this->contributor([new BudgetConstraint($this->scopeKey, 1, 100, 100)]);
    $runtime = new ExtensionRuntime($this->app->make(DefinitionRegistry::class));
    $route = $this->app->make(CatalogQueries::class)->findRoute($this->tenant, $this->catalog['route']);
    $extra = $runtime->contribute($intent['request'], $route, $this->tenant, ['fixture.extra'], [$this->scopeKey]);
    $constraint = BudgetConstraintSet::mergeMandatoryWithContributed([$origin], $extra)->byScopeKey[$this->scopeKey];
    expect($constraint->emissionIntervalUs)->toBe(100)->and($constraint->maxInflight)->toBe(1)
        ->and($constraint->hourLimit)->toBe(2)->and($constraint->mandatory)->toBeTrue();
    $promoted = BudgetConstraintSet::mergeMandatoryWithContributed([new BudgetConstraint($this->scopeKey, 1, hourLimit: 2, dayLimit: 3)])->byScopeKey[$this->scopeKey];
    expect($promoted->mandatory)->toBeTrue()->and($promoted->hourLimit)->toBe(2)->and($promoted->dayLimit)->toBe(3);
    $this->contributor([]);
    $input = $this->reservationInput($intent);
    expect($input->constraints[$this->scopeKey]->mandatory)->toBeTrue();
    $ticket = $this->reservations->enqueue($input, $this->work);
    $this->reservations->reserve($ticket, $input);
    expect($this->table('reservations')->where('budget_id', $this->table('budgets')->where('scope_key', $this->scopeKey)->value('id'))->count())->toBe(1);
});

it('contributor отклоняет чужой tenant key и незаявленную namespace до materialization', function (): void {
    $schema = new BudgetScopeSchema('consumer');
    $key = $schema->prefix(new TenantKey('foreign')).'quota';
    $registry = conformanceRegistry($this, [new ExtensionDefinition('bad', ExtensionKind::BudgetScopeContributor,
        new FixtureBudgetContributor([new BudgetConstraint($key, 1)]), budgetSchema: $schema)]);
    $runtime = new ExtensionRuntime($registry);
    $intent = $this->demandOperation();
    $route = $this->app->make(CatalogQueries::class)->findRoute($this->tenant, $this->catalog['route']);
    expect(fn (): array => $runtime->contribute($intent['request'], $route, $this->tenant, ['bad'], [$this->scopeKey]))
        ->toThrow(AdmissionRejected::class, 'undeclared_budget_scope');
    expect($this->table('demands')->count())->toBe(0)->and($this->table('permits')->count())->toBe(0);
});

it('shared contributor требует явный tenant grant', function (bool $grant): void {
    $key = 'shared:consumer';
    $this->budget($key, TenantKey::system());
    $registry = conformanceRegistry($this, [new ExtensionDefinition('shared', ExtensionKind::BudgetScopeContributor,
        new FixtureBudgetContributor([new BudgetConstraint($key, 1)]), budgetSchema: new BudgetScopeSchema('consumer', keys: [$key], sharedTenants: $grant ? [$this->tenant->value] : []))]);
    $this->app->instance(DefinitionRegistry::class, $registry);
    $this->reconfigureProxyma(['extensions.budget_contributors' => ['shared']]);
    $this->reservations = $this->app->make(ReservationService::class);
    $input = $this->reservationInput($this->demandOperation());
    $ticket = $this->reservations->enqueue($input, $this->work);
    if (! $grant) {
        expect(fn () => $this->reservations->reserve($ticket, $input))->toThrow(AdmissionRejected::class, 'budget_sharing_grant_required');
        expect($this->table('permits')->count())->toBe(0)->and($this->table('budgets')->where('scope_key', $key)->value('inflight'))->toBe(0);

        return;
    }
    $this->reservations->reserve($ticket, $input);
    expect($this->table('budgets')->where('scope_key', $key)->value('inflight'))->toBe(1);
})->with([false, true]);

it('freeze отвергает guard с ранним tier, несовпадением tier или без SQL binding', function (string $case): void {
    $guard = new ConformanceGuard($this->db, $this->app->make(AuthorityLocker::class), declared: $case === 'mismatch' ? AdmissionLockTier::FinalCas : AdmissionLockTier::AdapterGuard);
    if ($case === 'no_binding') {
        $guard = new class implements LockedAdmissionGuard
        {
            public function tier(): AdmissionLockTier
            {
                return AdmissionLockTier::AdapterGuard;
            }

            public function evaluateLocked(WireRequest $request, LockedOwnerState $owner, LockedTransactionContext $transaction): ?ClaimDenied
            {
                return null;
            }
        };
    }
    expect(fn (): DefinitionRegistry => conformanceRegistry($this, [new ExtensionDefinition('guard', ExtensionKind::LockedAdmissionGuard, $guard,
        tier: $case === 'early' ? AdmissionLockTier::OwnerPrefix : AdmissionLockTier::AdapterGuard)]))->toThrow(DefinitionError::class);
})->with(['early', 'mismatch', 'no_binding']);

it('locked guard не берёт ранние owner locks, не меняет wire и может только deny', function (string $attack, bool $claim): void {
    $prepared = $claim ? $this->prepareClaim() : null;
    $before = $this->accounting();
    $guard = new ConformanceGuard($this->db, $this->app->make(AuthorityLocker::class), $attack);
    $registry = conformanceRegistry($this, [new ExtensionDefinition('guard', ExtensionKind::LockedAdmissionGuard, $guard, tier: AdmissionLockTier::AdapterGuard)]);
    $this->app->instance(DefinitionRegistry::class, $registry);
    $this->reconfigureProxyma(['extensions.locked_admission_guards' => ['guard']]);
    $exception = match ($attack) {
        'owner_lock' => LogicException::class, 'wire' => Error::class, 'deny' => AdmissionRejected::class
    };
    if ($claim) {
        $this->app->forgetInstance(ClaimSend::class);
        $claims = $this->app->make(ClaimSend::class);
        $claims->prepare(...$prepared);
        expect(fn () => $claims->claim(...$prepared))->toThrow($exception);
    } else {
        $this->reservations = $this->app->make(ReservationService::class);
        $input = $this->reservationInput($this->demandOperation());
        $ticket = $this->reservations->enqueue($input, $this->work);
        expect(fn () => $this->reservations->reserve($ticket, $input))->toThrow($exception);
    }
    expect($this->accounting())->toBe($before)->and($this->table('attempts')->count())->toBe(0)->and($this->physical->sends)->toBe(0);
})->with(['owner_lock', 'wire', 'deny'])->with([false, true]);

it('observer видит committed completion, его ошибка сообщается и HTTP не повторяется', function (): void {
    $this->reconfigureProxyma(['policy.project_defaults' => ['route.allow_direct' => true], 'app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    $observer = new class($this->db) implements OutboundObserver
    {
        public int $calls = 0;

        public bool $committed = false;

        public function __construct(private Connection $db) {}

        public function onAttemptCompleted(ReadOnlyAttemptObservation $observation): void
        {
            $this->calls++;
            $this->committed = $this->db->transactionLevel() === 0
                && $this->db->table('pxm_attempts')->where('physical_attempt_id', $observation->physicalAttemptId->value)->value('status') === 'completed';
            throw new RuntimeException('sensitive observer failure');
        }
    };
    $following = new class implements OutboundObserver
    {
        public int $calls = 0;

        public function onAttemptCompleted(ReadOnlyAttemptObservation $observation): void
        {
            $this->calls++;
        }
    };
    $registry = conformanceRegistry($this, [new ExtensionDefinition('observer', ExtensionKind::Observer, $observer),
        new ExtensionDefinition('following', ExtensionKind::Observer, $following, dependencies: ['observer'], priority: 1)]);
    $logger = new class extends AbstractLogger
    {
        public array $events = [];

        public function log($level, Stringable|string $message, array $context = []): void
        {
            $this->events[] = [$level, $message, $context];
        }
    };
    $this->app->instance(AttemptObservers::class, new AttemptObservers($registry, $this->transactions->scope, $logger, ['observer', 'following']));
    $physical = new FixturePhysicalTransport;
    $this->app->instance(PhysicalTransport::class, $physical);
    $this->app->instance(DestinationPolicy::class, new DestinationPolicy(new DnsResolver($this->transactions->scope, static fn (): array => ['93.184.216.34'])));
    $id = Uuid::random();
    $operation = new OperationCorrelation($id, new OwnerRef($this->tenant, 'standalone', $id->value, 'token'), new DateTimeImmutable('+2 minutes'), 'observer:'.$id->value);
    $context = new ExecutionContext($operation, new SubjectChain(new SubjectRef($this->tenant, 'document', 'fixture')), new Duration(60000), hrtime(true) + 60000000000);
    $request = new OutboundRequest($id, $this->catalog['target'], 'GET', new SafeUrl('https://example.test/path'), new RequestTimeoutPolicy(500, 1000, 2000, 1000));
    $result = $this->app->make(OutboundExecutor::class)->execute($request, $context);
    expect($result)->toBeInstanceOf(ExecutionSucceeded::class)->and($observer->calls)->toBe(1)->and($physical->sends)->toBe(1)
        ->and($observer->committed)->toBeTrue()->and($following->calls)->toBe(1)
        ->and($this->table('attempts')->where('operation_id', $id->value)->value('status'))->toBe('completed')
        ->and($this->table('observations')->count())->toBe(1)
        ->and($logger->events)->toHaveCount(1)
        ->and($logger->events[0][1])->toBe('proxyma.observer_failed')
        ->and(json_encode($logger->events))->not->toContain('sensitive observer failure');
});

it('named policy definitions immutable, versioned и не допускают duplicate key', function (): void {
    $provider = new class implements PolicyDefinitionProvider
    {
        public function definitions(): array
        {
            return [new PolicyDefinition('consumer.read', new RequestTimeoutPolicy(100, 100, 100, 0))];
        }
    };
    $definition = new ExtensionDefinition('policies', ExtensionKind::PolicyDefinitions, $provider);
    $registry = conformanceRegistry($this, [$definition]);
    expect($registry->policy('consumer.read')->timeouts->totalTimeoutMs)->toBe(100);
    expect(fn (): PolicyDefinition => $registry->policy('missing'))->toThrow(DefinitionError::class);
    expect(fn (): DefinitionRegistry => conformanceRegistry($this, [$definition,
        new ExtensionDefinition('duplicate', ExtensionKind::PolicyDefinitions, $provider, priority: 1)]))->toThrow(DefinitionError::class);
});
