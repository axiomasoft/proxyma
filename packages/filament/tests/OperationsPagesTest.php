<?php

declare(strict_types=1);

use Filament\Panel;
use Illuminate\Database\Connection;
use Livewire\Livewire;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Catalog\CatalogConflict;
use Proxyma\Application\Health\ResetHealth;
use Proxyma\Application\Health\ReviewedProbe;
use Proxyma\Contracts\Execution\PhysicalTransport;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Execution\AttemptResult;
use Proxyma\Domain\Execution\AuthorizedWireRequest;
use Proxyma\Domain\Execution\DecodedTextBody;
use Proxyma\Domain\Execution\ResponseDescriptor;
use Proxyma\Domain\Execution\TransportFailure;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\HealthScope;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Filament\Pages\Budgets\BudgetsPage;
use Proxyma\Filament\Pages\Health\HealthPage;
use Proxyma\Filament\Pages\Operations\AttemptsPage;
use Proxyma\Filament\Pages\Operations\AuditPage;
use Proxyma\Filament\ProxymaPlugin;
use Proxyma\Filament\Queries\OperationsQuery;
use Proxyma\Filament\Tests\Support\WithFilament;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Http\DnsResolver;
use Proxyma\Tests\Support\CatalogTestCase;
use Proxyma\Tests\Support\ClaimFixtures;
use Proxyma\Tests\Support\DemandFixtures;
use Proxyma\Tests\Support\HealthFixtures;
use Proxyma\Tests\Support\QuotaFixtures;
use Proxyma\Tests\Support\RecoveryFixtures;
use Proxyma\Tests\Support\ReservationFixtures;

foreach (['CatalogTestCase', 'SelectionFixtures', 'DemandFixtures', 'ReservationFixtures', 'ClaimFixtures', 'QuotaFixtures', 'RecoveryFixtures', 'HealthFixtures'] as $file) {
    require_once __DIR__.'/../../core/tests/Support/'.$file.'.php';
}
require_once __DIR__.'/Support/WithFilament.php';

uses(CatalogTestCase::class, WithFilament::class, DemandFixtures::class, ReservationFixtures::class,
    ClaimFixtures::class, QuotaFixtures::class, RecoveryFixtures::class, HealthFixtures::class)->group('pg');

beforeEach(function (): void {
    $this->prepareFilament();
    $this->initializeHealthFixture();
    $this->reconfigureProxyma(['policy.project_defaults' => ['route.allow_direct' => true]]);
    $this->app->instance(DnsResolver::class, new DnsResolver($this->transactions->scope, static fn (): array => ['93.184.216.34']));
    $this->operatorTransport = new class($this->db) implements PhysicalTransport
    {
        public int $sends = 0;

        public bool $fail = false;

        public function __construct(private Connection $db) {}

        public function sendOnce(AuthorizedWireRequest $request): AttemptResult
        {
            expect($this->db->transactionLevel())->toBe(0);
            $request->consume();
            $this->sends++;

            return $this->fail ? AttemptResult::failed(TransportFailure::Timeout)
                : AttemptResult::delivered(new ResponseDescriptor(200, ['x-private' => ['fixture-secret-header']], new DecodedTextBody('fixture-secret-body'), 19, 19));
        }
    };
    $this->app->instance(PhysicalTransport::class, $this->operatorTransport);
});

function operatorProbePage(object $test): object
{
    $catalog = (fn () => $this->catalog)->call($test);

    return Livewire::test(HealthPage::class)
        ->set('probeSelection', ['profile' => $catalog['profile']->value, 'route' => $catalog['route']->value,
            'target' => $catalog['target']->value, 'subject_type' => 'operator', 'subject_id' => 'fixture'])
        ->call('previewProbe')->assertHasNoErrors();
}

function operatorCoreProbe(object $test, ?array $review = null, ?CatalogMutationActor $actor = null): array
{
    return (function () use ($review, $actor): array {
        $service = $this->app->make(ReviewedProbe::class);
        $subjects = new SubjectChain(new SubjectRef($this->tenant, 'operator', 'fixture'));
        $review ??= $service->review($subjects, $this->catalog['profile'], $this->catalog['route'], $this->catalog['target']);
        $actor ??= $this->actor($review['versions']['policy']);

        return $service->execute($subjects, $this->catalog['profile'], $this->catalog['route'], $this->catalog['target'],
            $review['versions'], $review['epochs'], $actor);
    })->call($test);
}

it('регистрирует operator pages без запросов при создании plugin', function (): void {
    $queries = [];
    $this->db->listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $panel = Panel::make()->id('operator-pages')->plugin(ProxymaPlugin::make());
    expect($panel->getPages())->toContain(BudgetsPage::class, HealthPage::class, AttemptsPage::class, AuditPage::class)
        ->and($queries)->toBe([]);
});

it('показывает safe empty state и запрещает доступ при default deny', function (string $page, string $kind, string $empty): void {
    $this->useTenant(new TenantKey('empty-tenant'));
    Livewire::test($page)->assertSee($empty);
    $this->permissions(fn (): bool => false);
    expect(fn () => app(OperationsQuery::class)->page($kind))->toThrow(AuthorizationDenied::class);
    Livewire::test($page)->assertForbidden();
})->with([
    [BudgetsPage::class, 'budgets', 'Нет доступных бюджетов'],
    [HealthPage::class, 'health', 'Нет доступных health scopes'],
    [AttemptsPage::class, 'attempts', 'Нет доступных попыток'],
    [AuditPage::class, 'audit', 'Нет доступных событий аудита'],
]);

it('разделяет consumed reserved fixed UTC windows GCRA и lease inflight', function (): void {
    $this->quotaPrepared();
    $this->authorized();
    $record = collect(app(OperationsQuery::class)->page('budgets')->items())->firstWhere('id', $this->originBudget->value);
    expect($record['quotas'])->toHaveCount(2)->and($record['inflight'])->toBe(2);
    foreach ($record['quotas'] as $quota) {
        expect($quota['consumed'])->toBe(1)->and($quota['reserved'])->toBe(1)->and($quota['timezone'])->toBe('UTC')
            ->and($quota['starts_at'])->toEndWith('Z')->and($quota['ends_at'])->toEndWith('Z');
    }
    Livewire::test(BudgetsPage::class)->assertSee('GCRA rate')->assertSee('Fixed UTC calendar hour')->assertSee('consumed 1')
        ->assertSee('reserved 1')->assertSee('не число физических сокетов')->assertDontSee('https://example.test');
});

it('next eligible учитывает оба GCRA debts cooldown quota и inflight', function (): void {
    $id = $this->originBudget->value;
    $row = fn () => collect(app(OperationsQuery::class)->page('budgets')->items())->firstWhere('id', $id);
    $this->table('budgets')->where('id', $id)->update(['emission_interval_us' => 1000000, 'burst' => 2,
        'tat' => '2030-06-01T10:10:04Z', 'send_tat' => '2030-06-01T10:10:06Z']);
    expect($row()['next_eligible_at'])->toBe('2030-06-01T10:10:05.000000Z');
    $this->table('budgets')->where('id', $id)->update(['cooldown_until' => '2030-06-01T10:15:00Z']);
    expect($row()['next_eligible_at'])->toBe('2030-06-01T10:15:00.000000Z');
    $this->quota->configure($this->tenant, $this->originBudget, 1, 40, 2);
    $this->table('quota_windows')->where('budget_id', $id)->where('window_kind', 'hour')->update(['spent' => 1]);
    expect($row()['next_eligible_at'])->toBe('2030-06-01T11:00:00.000000Z');
    $this->table('budgets')->where('id', $id)->update(['inflight' => 100]);
    expect($row()['next_eligible_at'])->toBeNull();
});

it('нулевая квота запрещает а отсутствующая дополнительная квота не обещает unlimited', function (): void {
    $this->quota->configure($this->tenant, $this->originBudget, 0, null, 2);
    $row = collect(app(OperationsQuery::class)->page('budgets')->items())->firstWhere('id', $this->originBudget->value);
    expect($row['quotas'][0]['limit'])->toBe(0)->and($row['next_eligible_at'])->toBeNull();
    Livewire::test(BudgetsPage::class)->assertSee('остальные ограничения действуют');
});

it('изменяет quota через core с actor reason revision и сохраняет debt reserved consumed', function (): void {
    $this->quotaPrepared();
    $before = $this->table('budgets')->where('id', $this->originBudget->value)->first();
    Livewire::test(BudgetsPage::class)->call('edit', $this->originBudget->value)->set('data.hour_limit', 1)
        ->set('data.day_limit')->set('data.reason', 'Снизить лимит')->set('data.tenant_key', 'foreign')
        ->call('updateBudget')->assertHasNoErrors();
    $after = $this->table('budgets')->where('id', $this->originBudget->value)->first();
    $audit = $this->table('audit_events')->where('ability', 'catalog.budget.configure')->orderByDesc('revision')->first();
    expect((int) $after->hour_limit)->toBe(1)->and($after->day_limit)->toBeNull()->and((int) $after->revision)->toBe(3)
        ->and($after->tat)->toBe($before->tat)->and($after->send_tat)->toBe($before->send_tat)->and($after->inflight)->toBe($before->inflight)
        ->and($audit->reason)->toBe(hash('sha256', 'Снизить лимит'))->and($audit->actor_id)->toBe(hash('sha256', 'fixture-operator'))
        ->and((int) $this->table('quota_windows')->where('budget_id', $after->id)->where('window_kind', 'hour')->value('reserved'))->toBe(1);
});

it('прямой updateBudget отвергает hidden button missing reason и stale form без записи', function (): void {
    $page = Livewire::test(BudgetsPage::class)->call('edit', $this->originBudget->value);
    $page->set('data.hour_limit', 0)->call('updateBudget')->assertHasErrors(['data.reason']);
    $this->quota->configure($this->tenant, $this->originBudget, 10, 40, 2);
    $page->set('data.reason', 'stale')->call('updateBudget')->assertHasErrors(['data']);
    expect((int) $this->table('budgets')->where('id', $this->originBudget->value)->value('hour_limit'))->toBe(10);
    $this->permissions(fn ($actor, $tenant, $ability): bool => $ability !== 'catalog.budget.configure');
    Livewire::test(BudgetsPage::class)->call('edit', $this->originBudget->value)->assertDontSee('Обновить квоту')
        ->call('updateBudget')->assertForbidden();
});

it('оператор не редактирует производную traffic quota независимо от parent policy', function (): void {
    $this->trafficShares(['status' => 20]);
    $id = $this->quota->configureTrafficShare($this->tenant, $this->originBudget,
        CanonicalOrigin::of('https', 'example.test'), 'status', 2);
    $before = $this->table('budgets')->where('id', $id->value)->first();
    Livewire::test(BudgetsPage::class)->call('edit', $id->value)->assertDontSee('Обновить квоту')
        ->set('data.reason', 'Независимая правка')->set('data.hour_limit', 999)->call('updateBudget')->assertForbidden();
    $after = $this->table('budgets')->where('id', $id->value)->first();
    expect($after->revision)->toBe($before->revision)->and($after->hour_limit)->toBe($before->hour_limit);
});

it('показывает route pair target holds отдельно и reset меняет только точный scope', function (string $scope): void {
    foreach (HealthScope::cases() as $case) {
        $this->table($case->value)->update(['state' => 'open', 'reason_code' => 'credential_hold:fixture-private-payload']);
    }
    $id = $this->table($scope)->value('id');
    $page = Livewire::test(HealthPage::class)->call('chooseScope', $scope)->assertSee('credential_hold')
        ->assertDontSee('fixture-private-payload')->call('edit', $id)->set('data.reason', 'Сброс точной области');
    $page->call('resetHealth')->assertHasNoErrors();
    foreach (HealthScope::cases() as $case) {
        $row = $this->table($case->value)->first();
        expect($row->state)->toBe($case->value === $scope ? 'unknown' : 'open')
            ->and((int) $row->health_epoch)->toBe($case->value === $scope ? 2 : 1);
    }
    $audit = $this->table('audit_events')->where('ability', 'health.reset')->sole();
    expect($audit->entity_type)->toBe($scope)->and($audit->entity_id)->toBe($id)
        ->and($audit->reason)->toBe(hash('sha256', 'Сброс точной области'));
})->with(['route_health', 'route_target_health', 'target_health']);

it('reset отвергает missing reason stale revision и отозванное разрешение на прямом вызове', function (): void {
    $id = $this->table('route_health')->value('id');
    $page = Livewire::test(HealthPage::class)->call('edit', $id);
    $page->call('resetHealth')->assertHasErrors(['data.reason']);
    $this->table('route_health')->update(['state_version' => 2]);
    $page->set('data.reason', 'stale')->call('resetHealth')->assertHasErrors(['data']);
    $this->permissions(fn ($actor, $tenant, $ability): bool => $ability !== 'health.reset');
    Livewire::test(HealthPage::class)->call('edit', $id)->assertDontSee('Reset в unknown')->call('resetHealth')->assertForbidden();
    expect($this->table('audit_events')->where('ability', 'health.reset')->count())->toBe(0);
});

it('не позволяет чужой ID или смену tenant на уже открытой форме', function (): void {
    $id = $this->table('route_health')->value('id');
    $budget = $this->originBudget->value;
    $healthPage = Livewire::test(HealthPage::class)->call('edit', $id)->set('data.reason', 'чужая область');
    $budgetPage = Livewire::test(BudgetsPage::class)->call('edit', $budget)->set('data.reason', 'чужая квота');
    $this->useTenant(new TenantKey('foreign'));
    $healthPage->call('resetHealth')->assertForbidden();
    $budgetPage->call('updateBudget')->assertForbidden();
    Livewire::test(HealthPage::class)->call('edit', $id)->assertNotFound();
    Livewire::test(BudgetsPage::class)->call('edit', $budget)->assertNotFound();
    expect((int) $this->table('route_health')->where('id', $id)->value('state_version'))->toBe(1);
});

it('probe требует reason использует exact reviewed scopes и повтор не отправляет новый HEAD', function (): void {
    $this->healthUnknown();
    $page = operatorProbePage($this)->call('probe')->assertHasErrors(['probeData.reason']);
    expect($this->operatorTransport->sends)->toBe(0);
    $page->set('probeData.reason', 'Проверить точную область')->call('probe')->assertHasNoErrors();
    $result = $page->get('probeResult');
    expect($result['status'])->toBe('probe_requested')->and($this->operatorTransport->sends)->toBe(1)
        ->and($this->table('attempts')->count())->toBe(1)->and($this->table('probe_claims')->count())->toBe(1)
        ->and((int) $this->table('quota_windows')->sum('spent'))->toBe(4);
    $page->call('probe')->assertHasNoErrors()->assertSet('probeResult', $result);
    expect($this->operatorTransport->sends)->toBe(1)->and($this->table('audit_events')->where('ability', 'health.probe.execute')->count())->toBe(1);
    $page->set('probeData.reason', 'Другой payload')->call('probe')->assertHasErrors(['data']);
    expect($this->operatorTransport->sends)->toBe(1);
});

it('probe проверяет права после preview и запрещает подставленный foreign tenant', function (): void {
    $this->healthUnknown();
    $page = operatorProbePage($this);
    $this->permissions(fn ($actor, $tenant, $ability): bool => $ability !== 'health.probe.execute');
    $page->set('probeData.reason', 'Запрещено')->call('probe')->assertForbidden();
    expect($this->operatorTransport->sends)->toBe(0)->and($this->table('probe_claims')->count())->toBe(0);
    $this->commands();
    $page = operatorProbePage($this);
    $this->useTenant(new TenantKey('foreign'));
    $page->set('probeData.reason', 'Другой tenant')->call('probe')->assertForbidden();
    expect($this->operatorTransport->sends)->toBe(0);
});

it('probe отвергает stale catalog health epoch и policy revision до bytes', function (string $change): void {
    $this->healthUnknown();
    $page = operatorProbePage($this)->set('probeData.reason', 'Устаревшее подтверждение');
    match ($change) {
        'route' => $this->table('routes')->update(['revision' => 2]),
        'profile' => $this->table('route_profiles')->update(['revision' => 2]),
        'target' => $this->table('targets')->update(['revision' => 2]),
        'epoch' => $this->table('route_target_health')->update(['health_epoch' => 2]),
        'state_version' => $this->table('target_health')->update(['state_version' => 2]),
        'policy' => $this->reconfigureProxyma(['policy.project_defaults' => ['route.allow_direct' => false]]),
    };
    $page->call('probe')->assertHasErrors(['data']);
    expect($this->operatorTransport->sends)->toBe(0)->and($this->table('attempts')->count())->toBe(0)
        ->and($this->table('probe_claims')->count())->toBe(0)->and((int) $this->table('quota_windows')->sum('spent'))->toBe(0);
})->with(['route', 'profile', 'target', 'epoch', 'state_version', 'policy']);

it('core reviewed probe отвергает неполный или лишний набор scope даже без UI', function (string $change): void {
    $this->healthUnknown();
    $subjects = new SubjectChain(new SubjectRef($this->tenant, 'operator', 'fixture'));
    $review = app(ReviewedProbe::class)->review($subjects, $this->catalog['profile'], $this->catalog['route'], $this->catalog['target']);
    if ($change === 'missing') {
        array_pop($review['epochs']);
    } else {
        $review['epochs']['route_health:'.Uuid::random()->value] = 1;
    }
    expect(fn (): array => operatorCoreProbe($this, $review))->toThrow(CatalogConflict::class)
        ->and($this->operatorTransport->sends)->toBe(0)->and($this->table('probe_claims')->count())->toBe(0);
})->with(['missing', 'extra']);

it('core reviewed probe проверяет trusted actor revision и command key без UI', function (string $bad): void {
    $this->healthUnknown();
    $subjects = new SubjectChain(new SubjectRef($this->tenant, 'operator', 'fixture'));
    $review = app(ReviewedProbe::class)->review($subjects, $this->catalog['profile'], $this->catalog['route'], $this->catalog['target']);
    $actor = new CatalogMutationActor(new ActorRef('operator', $bad === 'actor' ? 'forged' : 'fixture-operator'),
        'Проверка actor', $bad === 'revision' ? null : $review['versions']['policy'], $bad === 'key' ? null : Uuid::random()->value);
    expect(fn (): array => operatorCoreProbe($this, $review, $actor))->toThrow($bad === 'actor' ? AuthorizationDenied::class : CatalogConflict::class)
        ->and($this->operatorTransport->sends)->toBe(0)->and($this->table('operations')->count())->toBe(0);
})->with(['actor', 'revision', 'key']);

it('budget list читает постоянное число scoped queries при росте страницы', function (): void {
    $queries = [];
    $this->db->listen(function ($event) use (&$queries): void {
        $queries[] = $event->sql;
    });
    app(OperationsQuery::class)->page('budgets');
    $before = count($queries);
    for ($i = 0; $i < 26; $i++) {
        $this->budget('tenant:query-'.$i);
    }
    $queries = [];
    app(OperationsQuery::class)->page('budgets');
    expect(count($queries))->toBe($before)->toBeLessThanOrEqual(3);
});

it('locked claim повторно сверяет reviewed scopes после preflight race', function (): void {
    $this->healthUnknown();
    $changed = false;
    $this->db->beforeExecuting(function ($sql) use (&$changed): void {
        if (! $changed && str_starts_with(strtolower($sql), 'insert into "'.PackageSchema::tables()['operations'].'"')) {
            $changed = true;
            $this->table('route_health')->update(['state_version' => 2]);
        }
    });
    expect(fn (): array => operatorCoreProbe($this))->toThrow(AdmissionRejected::class, 'probe_review_changed')
        ->and($this->operatorTransport->sends)->toBe(0)->and($this->table('probe_claims')->count())->toBe(0)
        ->and((int) $this->table('quota_windows')->sum('spent'))->toBe(0);
});

it('probe с permanent hold или нулевым probe budget не выдаёт bytes и partial spend', function (string $block): void {
    $this->healthUnknown();
    if ($block === 'hold') {
        $this->table('route_target_health')->update(['state' => 'open', 'open_until' => null]);
    } else {
        $this->quota->configure($this->tenant, $this->probeBudget, 0, 0, 2);
    }
    $page = operatorProbePage($this)->set('probeData.reason', 'Заблокировано')->call('probe');
    if ($block === 'hold') {
        $page->assertHasErrors(['data']);
    }
    expect($this->operatorTransport->sends)->toBe(0)->and($this->table('attempts')->count())->toBe(0)
        ->and((int) $this->table('quota_windows')->sum('spent'))->toBe(0);
})->with(['hold', 'quota']);

it('unknown probe повтор возвращает receipt а attempts остаётся read-only и redacted', function (): void {
    $this->healthUnknown();
    $this->operatorTransport->fail = true;
    $page = operatorProbePage($this)->set('probeData.reason', 'Timeout probe')->call('probe')->assertHasNoErrors();
    $receipt = $page->get('probeResult');
    $page->call('probe')->assertHasNoErrors()->assertSet('probeResult', $receipt);
    expect($this->operatorTransport->sends)->toBe(1)->and($this->table('attempts')->value('status'))->toBe('unknown_after_authorization');
    $id = $this->table('attempts')->value('id');
    $before = $this->table('attempts')->get()->toJson();
    Livewire::test(AttemptsPage::class)->call('edit', $id)->assertSee('unknown_after_authorization')->assertSee('Только диагностика')
        ->assertDontSee('wire:click="retry', false)->assertDontSee('fixture-secret-header')->assertDontSee('fixture-secret-body')
        ->assertDontSee('https://example.test');
    expect($this->table('attempts')->get()->toJson())->toBe($before)->and(method_exists(AttemptsPage::class, 'retry'))->toBeFalse();
    $this->useTenant(new TenantKey('foreign'));
    Livewire::test(AttemptsPage::class)->call('edit', $id)->assertNotFound();
});

it('audit redaction не отдаёт secret payload patch result или command hash', function (): void {
    $id = $this->table('route_health')->value('id');
    Livewire::test(HealthPage::class)->call('edit', $id)->set('data.reason', 'fixture-secret-reason')->call('resetHealth')->assertHasNoErrors();
    Livewire::test(AuditPage::class)->assertSee(hash('sha256', 'fixture-secret-reason'))->assertDontSee('fixture-secret-reason')
        ->assertDontSee('fixture-operator')->assertDontSee('fixture-token')->assertDontSee('https://example.test');
    foreach (app(OperationsQuery::class)->page('audit')->items() as $row) {
        expect($row)->not->toHaveKeys(['result', 'command_hash', 'idempotency_key']);
    }
});

it('списки scoped indexed paginated bounded и повторяют row permission', function (string $kind): void {
    if ($kind === 'budgets') {
        for ($i = 0; $i < 27; $i++) {
            $this->budget('tenant:fixture-'.$i);
        }
    } elseif ($kind === 'health') {
        for ($i = 0; $i < 27; $i++) {
            $route = Uuid::random()->value;
            $this->table('routes')->insert(['id' => $route, 'tenant_key' => $this->tenant->value, 'route_key' => 'page-'.$i,
                'kind' => 'direct', 'egress_key' => 'nat:fixture', 'conservative_pool' => false]);
            $this->table('route_health')->insert(['tenant_key' => $this->tenant->value,
                'route_id' => $route, 'id' => Uuid::random()->value]);
        }
    } elseif ($kind === 'attempts') {
        $this->quota->configure($this->tenant, $this->originBudget, 100, 100, 2);
        for ($i = 0; $i < 27; $i++) {
            $this->authorized();
        }
    } else {
        $id = new Uuid($this->table('route_health')->value('id'));
        for ($i = 1; $i <= 27; $i++) {
            app(ResetHealth::class)->handle(HealthScope::Route, $id, $this->tenant, $this->actor($i));
        }
    }
    $query = app(OperationsQuery::class);
    $first = $query->page($kind);
    expect($first->items())->toHaveCount(25)->and($first->hasMorePages())->toBeTrue()->and($query->page($kind, 2)->items())->not->toBeEmpty();
    $id = $first->items()[0]['id'];
    $this->permissions(fn ($actor, $tenant, $ability, $resource): bool => $ability !== "operations.{$kind}.read" || $resource->value !== $id);
    expect(array_column($query->page($kind)->items(), 'id'))->not->toContain($id);
    $table = PackageSchema::tables()[match ($kind) {
        'health' => 'route_health', 'audit' => 'audit_events', default => $kind
    }];
    $this->db->statement('SET enable_seqscan=off');
    try {
        $plan = $this->db->select('EXPLAIN SELECT id FROM '.$table.' WHERE tenant_key=? ORDER BY id LIMIT 26', [$this->tenant->value]);
        expect(json_encode($plan))->toContain('Index');
    } finally {
        $this->db->statement('SET enable_seqscan=on');
    }
    $this->useTenant(new TenantKey('foreign'));
    expect($query->page($kind)->items())->toBe([]);
})->with(['budgets', 'health', 'attempts', 'audit']);
