<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Application\Panels\PanelFormProjection;
use Proxyma\Application\Panels\PanelSubjects;
use Proxyma\Application\Policy\PolicyResolver;
use Proxyma\Application\Policy\SettingRegistry;
use Proxyma\Contracts\Admission\BudgetScopeContributor;
use Proxyma\Contracts\Admission\LockedAdmissionGuard;
use Proxyma\Contracts\Admission\SqlConnectionParticipant;
use Proxyma\Contracts\Bindings\BindingCommands;
use Proxyma\Contracts\Bindings\BindingQueries;
use Proxyma\Contracts\Bindings\SubjectResolver;
use Proxyma\Contracts\Routing\RouteFilter;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Execution\AdmissionLockTier;
use Proxyma\Domain\Execution\ClaimDenied;
use Proxyma\Domain\Execution\ExecutionBlocked;
use Proxyma\Domain\Execution\LockedOwnerState;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Panels\BudgetScopeSchema;
use Proxyma\Domain\Panels\DefinitionError;
use Proxyma\Domain\Panels\ExtensionDefinition;
use Proxyma\Domain\Panels\ExtensionKind;
use Proxyma\Domain\Panels\PanelSlot;
use Proxyma\Domain\Panels\SubjectResolutionFailed;
use Proxyma\Domain\Panels\SubjectResolverDefinition;
use Proxyma\Domain\Policy\BindSubjectPolicyCommand;
use Proxyma\Domain\Policy\InvalidSettingPatch;
use Proxyma\Domain\Policy\PanelDefinition;
use Proxyma\Domain\Policy\PolicyConflict;
use Proxyma\Domain\Policy\SettingChange;
use Proxyma\Domain\Policy\SettingPatch;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Domain\Policy\UnbindSubjectPolicyCommand;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Database\PanelBindingSchema;
use Proxyma\Tests\Support\CatalogTestCase;

require_once __DIR__.'/../Support/CatalogTestCase.php';

uses(CatalogTestCase::class)->group('pg');

// Два consumer-типа существуют только в fixture; Domain ничего о них не знает.
final class PanelFixtureSubjects implements SubjectResolver
{
    /** @var array<string, SubjectChain> */
    public array $chains = [];

    public function __construct(private Connection $db) {}

    public function put(SubjectRef $subject, array $ancestors = []): void
    {
        $this->chains[$subject->key()] = new SubjectChain($subject, $ancestors);
    }

    public function resolve(SubjectRef $leaf): ?SubjectChain
    {
        if ($this->db->transactionLevel() !== 0) {
            throw new LogicException('Subject I/O inside a SQL transaction.');
        }

        return $this->chains[$leaf->key()] ?? null;
    }
}

final class PanelFixtureFilter implements RouteFilter
{
    public function filter(array $profileRoutes, WireRequest $request): array
    {
        throw new LogicException('Registry must not execute handlers.');
    }
}

final class PanelFixtureBudgets implements BudgetScopeContributor
{
    public function contribute(WireRequest $request, Route $route, CanonicalOrigin $origin): array
    {
        throw new LogicException('Registry must not execute handlers.');
    }
}

final class PanelFixtureGuard implements LockedAdmissionGuard, SqlConnectionParticipant
{
    public function connection(): Connection
    {
        throw new LogicException('Freeze must not resolve a connection.');
    }

    public function tier(): AdmissionLockTier
    {
        return AdmissionLockTier::AdapterGuard;
    }

    public function evaluateLocked(WireRequest $request, LockedOwnerState $owner, LockedTransactionContext $transaction): ?ClaimDenied
    {
        throw new LogicException('Registry must not execute handlers.');
    }
}

function panelPatch(int $connect): SettingPatch
{
    return new SettingPatch(1, ['timeout.connect_ms' => SettingChange::set($connect)]);
}

beforeEach(function (): void {
    $this->catalog = $this->provision();
    $this->subjects = new PanelFixtureSubjects($this->db);
    $this->folder = new SubjectRef($this->tenant, 'folder', 'root');
    $this->document = new SubjectRef($this->tenant, 'document', '42');
    $this->subjects->put($this->folder);
    $this->subjects->put($this->document, [$this->folder]);
    $definitions = new DefinitionRegistry($this->app->make(SettingRegistry::class));
    $definitions->registerCapability('network');
    $definitions->registerSubjectResolver(new SubjectResolverDefinition('files', $this->subjects, ['folder', 'document']));
    foreach (['files', 'archive'] as $panel) {
        $definitions->registerPanel(new PanelDefinition($panel, 'files', [
            new PanelSlot('read', ['timeout.connect_ms'], panelPatch(1500)),
            new PanelSlot('write', ['timeout.connect_ms'], panelPatch(1200)),
            new PanelSlot('explicit', ['timeout.connect_ms'], inheritDefaults: false),
        ], ['network']));
    }
    $definitions->freeze();
    $this->definitions = $definitions;
    $this->app->instance(DefinitionRegistry::class, $definitions);
    $this->bindings = $this->app->make(BindingCommands::class);
    $this->queries = $this->app->make(BindingQueries::class);
    $this->policies = $this->app->make(PolicyResolver::class);
});

it('замораживает PHP definitions без SQL и независимо от порядка регистрации', function (): void {
    $this->db->enableQueryLog();
    $this->db->flushQueryLog();
    $make = function (bool $reverse): DefinitionRegistry {
        $r = new DefinitionRegistry($this->app->make(SettingRegistry::class));
        $r->registerCapability('network');
        $r->registerSubjectResolver(new SubjectResolverDefinition('files', $this->subjects, $reverse ? ['folder', 'document'] : ['document', 'folder']));
        foreach ($reverse ? ['b', 'a'] : ['a', 'b'] as $id) {
            $r->registerPanel(new PanelDefinition($id, 'files', [new PanelSlot('read', ['timeout.connect_ms'])], ['network']));
        }
        $r->registerExtension(new ExtensionDefinition('filter', ExtensionKind::RouteFilter, new PanelFixtureFilter));
        $r->registerExtension(new ExtensionDefinition('budget', ExtensionKind::BudgetScopeContributor, new PanelFixtureBudgets, budgetSchema: new BudgetScopeSchema('fixture')));
        $r->registerExtension(new ExtensionDefinition('guard', ExtensionKind::LockedAdmissionGuard, new PanelFixtureGuard, tier: AdmissionLockTier::AdapterGuard));
        $r->freeze();

        return $r;
    };
    $a = $make(false);
    $b = $make(true);
    expect($a->fingerprint())->toBe($b->fingerprint())
        ->and($a->freeze())->toBe($b->freeze())
        ->and($a->selectExtensions(ExtensionKind::RouteFilter, ['filter'])[0]->id)->toBe('filter')
        ->and($a->selectExtensions(ExtensionKind::BudgetScopeContributor, ['budget']))->toHaveCount(1)
        ->and($a->selectExtensions(ExtensionKind::LockedAdmissionGuard, ['guard']))->toHaveCount(1)
        ->and($this->db->getQueryLog())->toBe([]);
    expect(fn () => $a->registerCapability('late'))->toThrow(DefinitionError::class);
    foreach (['App\\Injected', 'missing'] as $id) {
        expect(fn (): array => $a->selectExtensions(ExtensionKind::RouteFilter, [$id]))->toThrow(DefinitionError::class);
    }
    expect(fn (): array => $a->selectExtensions(ExtensionKind::RouteFilter, ['budget']))->toThrow(DefinitionError::class);
});

it('отклоняет дубликаты и отсутствующие ссылки definitions', function (): void {
    $make = fn (): DefinitionRegistry => new DefinitionRegistry($this->app->make(SettingRegistry::class));
    $r = $make();
    $r->registerCapability('network');
    expect(fn () => $r->registerCapability('network'))->toThrow(DefinitionError::class);
    $resolver = new SubjectResolverDefinition('files', $this->subjects, ['folder', 'document']);
    $r->registerSubjectResolver($resolver);
    expect(fn () => $r->registerSubjectResolver($resolver))->toThrow(DefinitionError::class);
    $panel = new PanelDefinition('files', 'files', [new PanelSlot('read', ['timeout.connect_ms'])]);
    $r->registerPanel($panel);
    expect(fn () => $r->registerPanel($panel))->toThrow(DefinitionError::class);
    foreach ([
        new PanelDefinition('bad', 'absent', [new PanelSlot('read', ['timeout.connect_ms'])]),
        new PanelDefinition('bad', 'files', [new PanelSlot('read', ['timeout.connect_ms'])], ['absent']),
        new PanelDefinition('bad', 'files', [new PanelSlot('read', ['unknown'])]),
        new PanelDefinition('bad', 'files', [new PanelSlot('read', [], panelPatch(100))]),
    ] as $bad) {
        $r = $make();
        $r->registerSubjectResolver($resolver);
        $r->registerPanel($bad);
        expect(fn (): string => $r->freeze())->toThrow(InvalidArgumentException::class);
        expect(fn (): string => $r->fingerprint())->toThrow(DefinitionError::class);
    }
    expect(fn (): PanelDefinition => new PanelDefinition('bad', 'files', [new PanelSlot('read', []), new PanelSlot('read', [])]))->toThrow(DefinitionError::class);
});

it('отклоняет missing dependency, cycle, priority и lock-tier conflicts при freeze', function (): void {
    $filter = fn (string $id, array $deps = [], int $priority = 0): ExtensionDefinition => new ExtensionDefinition($id, ExtensionKind::RouteFilter, new PanelFixtureFilter, dependencies: $deps, priority: $priority);
    $guard = fn (string $id, AdmissionLockTier $tier, array $deps = []): ExtensionDefinition => new ExtensionDefinition($id, ExtensionKind::LockedAdmissionGuard, new PanelFixtureGuard, dependencies: $deps, tier: $tier);
    foreach ([
        [$filter('a', ['missing'])],
        [$filter('a', ['b']), $filter('b', ['a'], 1)],
        [$filter('a'), $filter('b')],
        [$filter('a', ['b']), $filter('b', [], 1)],
        [$guard('a', AdmissionLockTier::OwnerPrefix, ['b']), $guard('b', AdmissionLockTier::FinalCas)],
        [$filter('a', ['budget']), new ExtensionDefinition('budget', ExtensionKind::BudgetScopeContributor, new PanelFixtureBudgets)],
    ] as $definitions) {
        $r = new DefinitionRegistry($this->app->make(SettingRegistry::class));
        foreach ($definitions as $definition) {
            $r->registerExtension($definition);
        }
        expect(fn (): string => $r->freeze())->toThrow(DefinitionError::class);
    }
    $r = new DefinitionRegistry($this->app->make(SettingRegistry::class));
    $r->registerExtension($filter('a'));
    expect(fn () => $r->registerExtension($filter('a')))->toThrow(DefinitionError::class);
    $r->registerExtension($filter('b', ['a'], 1));
    $r->freeze();
    expect(fn (): array => $r->selectExtensions(ExtensionKind::RouteFilter, ['b']))->toThrow(DefinitionError::class);
    expect(array_column($r->selectExtensions(ExtensionKind::RouteFilter, ['b', 'a']), 'id'))->toBe(['a', 'b']);
    expect(fn (): ExtensionDefinition => new ExtensionDefinition('bad', ExtensionKind::RouteFilter, new PanelFixtureBudgets))->toThrow(DefinitionError::class);
});

it('разрешает два consumer-типа и повтор типа с другим ID в root-to-leaf порядке', function (): void {
    $child = new SubjectRef($this->tenant, 'folder', 'child');
    $this->subjects->put($child, [$this->folder]);
    $this->subjects->put($this->document, [$this->folder, $child]);
    $chain = new PanelSubjects($this->definitions)->resolve('files', $this->document);
    expect(array_column($chain->ancestors(), 'id'))->toBe(['root', 'child'])
        ->and($chain->leaf->type)->toBe('document');
    expect(new SubjectRef($this->tenant, 'folder:a', 'b')->key())
        ->not->toBe(new SubjectRef($this->tenant, 'folder', 'a:b')->key());
});

it('отклоняет missing subject, orphan, conflicting parent и поддельную ancestry', function (): void {
    $resolver = new PanelSubjects($this->definitions);
    expect(fn (): SubjectChain => $resolver->resolve('files', new SubjectRef($this->tenant, 'document', 'missing')))->toThrow(SubjectResolutionFailed::class);
    unset($this->subjects->chains[$this->folder->key()]);
    expect(fn (): SubjectChain => $resolver->resolve('files', $this->document))->toThrow(SubjectResolutionFailed::class, 'Orphan');
    $this->subjects->put($this->folder, [new SubjectRef($this->tenant, 'folder', 'other')]);
    expect(fn (): SubjectChain => $resolver->resolve('files', $this->document))->toThrow(SubjectResolutionFailed::class, 'conflicting');
    $this->subjects->put($this->folder);
    expect(fn () => $resolver->verify('files', new SubjectChain($this->document)))->toThrow(SubjectResolutionFailed::class);
    expect(fn (): SubjectChain => $resolver->resolve('files', new SubjectRef($this->tenant, 'foreign', '1')))->toThrow(SubjectResolutionFailed::class);
    $this->subjects->chains[$this->document->key()] = new SubjectChain(new SubjectRef(new TenantKey('other'), 'document', '42'));
    expect(fn (): SubjectChain => $resolver->resolve('files', $this->document))->toThrow(SubjectResolutionFailed::class);
});

it('ограничивает chain шестнадцатью узлами и отклоняет repeated typed ID, nested и cross-tenant', function (): void {
    $parents = [];
    for ($i = 0; $i < 15; $i++) {
        $parent = new SubjectRef($this->tenant, 'folder', (string) $i);
        $this->subjects->put($parent, $parents);
        $parents[] = $parent;
    }
    $this->subjects->put($this->document, $parents);
    expect(new PanelSubjects($this->definitions)->resolve('files', $this->document)->ordered)->toHaveCount(16);
    expect(fn (): SubjectChain => new SubjectChain($this->document, [...$parents, $this->folder]))->toThrow(InvalidArgumentException::class, '16');
    expect(fn (): SubjectRef => new SubjectRef($this->tenant, 'document', '42', [...$parents, $this->folder]))->toThrow(InvalidArgumentException::class, '16');
    foreach ([[$this->document], [$this->folder, $this->folder]] as $cycle) {
        expect(fn (): SubjectChain => new SubjectChain($this->document, $cycle))->toThrow(InvalidArgumentException::class, 'Cycle');
        expect(fn (): SubjectRef => new SubjectRef($this->tenant, 'document', '42', $cycle))->toThrow(InvalidArgumentException::class, 'Cycle');
    }
    expect(fn (): SubjectChain => new SubjectChain($this->document, [new SubjectRef(new TenantKey('other'), 'folder', 'root')]))->toThrow(InvalidArgumentException::class);
    expect(fn (): SubjectChain => new SubjectChain($this->document, [new SubjectRef($this->tenant, 'folder', 'child', [$this->folder])]))->toThrow(InvalidArgumentException::class);
});

it('изолирует bindings по tenant, panel, typed subject и slot на query и resolution', function (): void {
    foreach ([[$this->document, 'files', 'read', 900], [$this->document, 'files', 'write', 800],
        [$this->document, 'archive', 'read', 700], [$this->folder, 'files', 'read', 1000]] as [$subject, $panel, $slot, $timeout]) {
        $binding = $this->bindings->bindPolicy(new BindSubjectPolicyCommand($subject, $this->catalog['profile'], 'default', $this->actor(), $panel, $slot, panelPatch($timeout)));
        expect($binding->panelKey)->toBe($panel)->and($binding->slotKey)->toBe($slot)->and($binding->revision)->toBe(1);
    }
    $chain = new PanelSubjects($this->definitions)->resolve('files', $this->document);
    foreach ([['files', 'read', 900], ['files', 'write', 800], ['archive', 'read', 700]] as [$panel, $slot, $timeout]) {
        expect($this->policies->resolveEffective($chain, $this->catalog['target'], trafficKey: $slot, panelKey: $panel)->timeouts->connectTimeoutMs)->toBe($timeout);
        expect($this->queries->findBinding($this->document, $panel, $slot)->patch->changes['timeout.connect_ms']->value)->toBe($timeout);
    }
    $other = new TenantKey('globex');
    $otherSubject = new SubjectRef($other, 'document', '42');
    expect(fn () => $this->queries->findBinding($otherSubject, 'files', 'read'))->toThrow(AuthorizationDenied::class);
    $this->useTenant($other);
    $this->subjects->put($otherSubject);
    expect($this->queries->findBinding($otherSubject, 'files', 'read'))->toBeNull();
    $this->bindings->bindPolicy(new BindSubjectPolicyCommand($otherSubject, null, 'default', $this->actor(), 'files', 'read', panelPatch(600)));
    expect($this->queries->findBinding($otherSubject, 'files', 'read')->patch->changes['timeout.connect_ms']->value)->toBe(600);
    $this->useTenant($this->tenant);
    expect($this->queries->findBinding($this->document, 'files', 'read')->patch->changes['timeout.connect_ms']->value)->toBe(900);
});

it('сохраняет immutable revisions, exact replay, CAS, inherit, retirement и rebind', function (): void {
    $first = new BindSubjectPolicyCommand($this->document, $this->catalog['profile'], 'default', $this->actor(0, 'first'), 'files', 'read', panelPatch(900));
    $created = $this->bindings->bindPolicy($first);
    $updated = $this->bindings->bindPolicy(new BindSubjectPolicyCommand($this->document, null, 'default', $this->actor(1, 'second'), 'files', 'read', panelPatch(800)));
    expect($updated->revision)->toBe(2);
    $replay = $this->bindings->bindPolicy($first);
    expect($replay->revision)->toBe(1)->and($replay->patch->changes['timeout.connect_ms']->value)->toBe(900)
        ->and($replay->routeProfileId->equals($this->catalog['profile']))->toBeTrue();
    expect(fn () => $this->bindings->bindPolicy(new BindSubjectPolicyCommand($this->document, null, 'default', $this->actor(1), 'files', 'read', panelPatch(500))))->toThrow(PolicyConflict::class);
    expect(fn () => $this->bindings->bindPolicy(new BindSubjectPolicyCommand($this->document, null, 'default', $first->actor, 'files', 'read', panelPatch(600))))->toThrow(PolicyConflict::class);
    $inherited = $this->bindings->bindPolicy(new BindSubjectPolicyCommand($this->document, null, 'default', $this->actor(2), 'files', 'read', new SettingPatch(1, ['timeout.connect_ms' => SettingChange::inherit()])));
    expect($inherited->patch->changes)->toBe([]);
    $this->bindings->unbindPolicy(new UnbindSubjectPolicyCommand($this->document, 'default', $this->actor(3, 'retire'), 'files', 'read'));
    expect($this->queries->findBinding($this->document, 'files', 'read')->retired)->toBeTrue();
    $reborn = $this->bindings->bindPolicy(new BindSubjectPolicyCommand($this->document, null, 'default', $this->actor(4), 'files', 'read', panelPatch(700)));
    expect($reborn->revision)->toBe(5)->and($reborn->bindingId->equals($created->bindingId))->toBeTrue()
        ->and($this->table('binding_revisions')->count())->toBe(5)
        ->and($this->table('audit_events')->where('entity_type', 'bindings')->count())->toBe(5);
    expect(fn () => PanelBindingSchema::down($this->db))->toThrow(LogicException::class, 'prevent rollback');
    $this->rejectedSql(fn () => $this->table('binding_revisions')->where('binding_id', $created->bindingId->value)->update(['policy_key' => 'tampered']));
    $this->rejectedSql(fn () => $this->table('bindings')->insert(['tenant_key' => $this->tenant->value, 'subject_type' => 'document', 'subject_id' => '42', 'panel_key' => 'files', 'slot_key' => 'read', 'head_id' => $created->bindingId->value]), '23505');
});

it('не делает частичных записей при wrong field, type, reference и stale revision', function (): void {
    $before = [$this->table('bindings')->count(), $this->table('policy_revisions')->count(), $this->table('audit_events')->count()];
    foreach ([new SettingPatch(1, ['route.allow_direct' => SettingChange::set(true)]), new SettingPatch(1, ['timeout.connect_ms' => SettingChange::set('fast')])] as $patch) {
        expect(fn () => $this->bindings->bindPolicy(new BindSubjectPolicyCommand($this->document, null, 'default', $this->actor(), 'files', 'read', $patch)))->toThrow(InvalidSettingPatch::class);
    }
    expect(fn () => $this->bindings->bindPolicy(new BindSubjectPolicyCommand($this->document, Uuid::random(), 'default', $this->actor(), 'files', 'read')))->toThrow(InvalidSettingPatch::class);
    expect(fn () => $this->bindings->bindPolicy(new BindSubjectPolicyCommand($this->document, null, 'default', $this->actor(4), 'files', 'read')))->toThrow(PolicyConflict::class);
    expect(fn () => $this->bindings->bindPolicy(new BindSubjectPolicyCommand($this->document, null, 'default', $this->actor(), 'files', 'missing')))->toThrow(DefinitionError::class);
    expect([$this->table('bindings')->count(), $this->table('policy_revisions')->count(), $this->table('audit_events')->count()])->toBe($before);
});

it('не вызывает consumer resolver внутри transaction и проверяет field permissions при unbind', function (): void {
    $chain = new PanelSubjects($this->definitions)->resolve('files', $this->document);
    expect(fn () => $this->db->transaction(fn () => $this->policies->resolveEffective($chain, $this->catalog['target'], trafficKey: 'read', panelKey: 'files')))
        ->toThrow(SubjectResolutionFailed::class, 'before opening');
    $this->bindings->bindPolicy(new BindSubjectPolicyCommand($this->document, null, 'default', $this->actor(), 'files', 'read', panelPatch(800)));
    $permission = $this->app->make(SettingRegistry::class)->descriptor('timeout.connect_ms')->permission;
    $this->permissions(fn ($actor, $tenant, string $ability): bool => $ability !== $permission);
    expect(fn () => $this->bindings->unbindPolicy(new UnbindSubjectPolicyCommand($this->document, 'default', $this->actor(1), 'files', 'read')))->toThrow(AuthorizationDenied::class);
    expect($this->queries->findBinding($this->document, 'files', 'read')->revision)->toBe(1);
});

it('отказывает UI, API и CLI по owner permission и required capability до transaction', function (): void {
    $this->permissions(function ($actor, $tenant, string $ability): bool {
        expect($this->db->transactionLevel())->toBe(0);

        return ! in_array($ability, ['binding.update', 'capability.network'], true);
    });
    expect(fn () => $this->bindings->bindPolicy(new BindSubjectPolicyCommand($this->document, null, 'default', $this->actor(), 'files', 'read')))->toThrow(AuthorizationDenied::class);
    expect(fn () => $this->queries->findBinding($this->document, 'files', 'read'))->toThrow(AuthorizationDenied::class);
    expect($this->table('bindings')->count())->toBe(0);
});

it('наследует разрешённые defaults, требует explicit binding и не выполняет missing subject из cache', function (): void {
    $chain = new PanelSubjects($this->definitions)->resolve('files', $this->document);
    $policy = $this->policies->resolveEffective($chain, $this->catalog['target'], trafficKey: 'read', panelKey: 'files');
    expect($policy->timeouts->connectTimeoutMs)->toBe(1500);
    expect(fn () => $this->policies->resolveEffective($chain, $this->catalog['target'], trafficKey: 'explicit', panelKey: 'files'))->toThrow(SubjectResolutionFailed::class, 'Explicit');
    $this->bindings->bindPolicy(new BindSubjectPolicyCommand($this->document, null, 'default', $this->actor(), 'files', 'read', panelPatch(800)));
    expect($this->policies->resolveEffective($chain, $this->catalog['target'], trafficKey: 'read', panelKey: 'files')->timeouts->connectTimeoutMs)->toBe(800);
    unset($this->subjects->chains[$this->document->key()]);
    expect(fn () => $this->policies->resolveEffective($chain, $this->catalog['target'], trafficKey: 'read', panelKey: 'files'))->toThrow(SubjectResolutionFailed::class);
    expect($this->policies->revalidateLive($policy->pin(), $chain, trafficKey: 'read', panelKey: 'files'))->toBeInstanceOf(ExecutionBlocked::class);
    $projection = $this->app->make(PanelFormProjection::class)->project($this->document, $this->catalog['target'], 'files', 'read');
    expect($projection->blockedReason)->not->toBeNull()->and($projection->fields)->toBe([]);
});

it('проецирует только разрешённые fields без Filament и повторяет update authorization', function (): void {
    $projection = $this->app->make(PanelFormProjection::class);
    $this->permissions(fn ($actor, $tenant, string $ability): bool => ! in_array($ability, ['binding.update', 'binding.execute'], true));
    $data = $projection->project($this->document, $this->catalog['target'], 'files', 'read');
    expect(array_keys($data->fields))->toBe(['timeout.connect_ms'])
        ->and($data->fields['timeout.connect_ms']['value'])->toBe(1500)
        ->and($data->fields['timeout.connect_ms']['editable'])->toBeFalse()
        ->and($data->blockedReason)->toBeNull();
    expect(fn () => $this->bindings->bindPolicy(new BindSubjectPolicyCommand($this->document, null, 'default', $this->actor(), 'files', 'read', panelPatch(500))))->toThrow(AuthorizationDenied::class);
});

it('сохраняет legacy bindings при upgrade с panel и slot default', function (): void {
    $head = Uuid::random();
    $version = Uuid::random();
    $binding = Uuid::random();
    $this->db->transaction(function () use ($head, $version, $binding): void {
        $this->table('policy_heads')->insert(['id' => $head->value, 'tenant_key' => $this->tenant->value, 'scope_key' => 'entity:document:42']);
        $this->table('policy_revisions')->insert(['id' => $version->value, 'tenant_key' => $this->tenant->value, 'head_id' => $head->value, 'revision' => 1, 'definition' => panelPatch(900)->encode()]);
        $this->table('policy_heads')->where('id', $head->value)->update(['current_revision_id' => $version->value]);
        $this->table('bindings')->insert(['id' => $binding->value, 'tenant_key' => $this->tenant->value, 'subject_type' => 'document', 'subject_id' => '42', 'head_id' => $head->value, 'profile_id' => $this->catalog['profile']->value]);
    });
    $table = PackageSchema::tables()['bindings'];
    $history = PackageSchema::tables()['binding_revisions'];
    $this->db->statement("DROP TABLE {$history}");
    $this->db->statement("ALTER TABLE {$table} DROP CONSTRAINT pxm_binding_scope_unique, DROP COLUMN panel_key, DROP COLUMN slot_key, DROP COLUMN policy_key,
        ADD CONSTRAINT legacy_binding_unique UNIQUE (tenant_key, subject_type, subject_id)");
    PanelBindingSchema::up($this->db);
    $row = $this->table('bindings')->where('id', $binding->value)->first();
    expect($row->panel_key)->toBe('default')->and($row->slot_key)->toBe('default')
        ->and($row->head_id)->toBe($head->value)->and($row->profile_id)->toBe($this->catalog['profile']->value)
        ->and((int) $row->revision)->toBe(1)->and($this->table('binding_revisions')->count())->toBe(1);
    $this->rejectedSql(fn () => $this->table('bindings')->insert(['tenant_key' => $this->tenant->value,
        'panel_key' => '', 'subject_type' => 'document', 'subject_id' => 'other', 'head_id' => $head->value]));
});
