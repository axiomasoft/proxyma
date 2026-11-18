<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Catalog\CatalogConflict;
use Proxyma\Application\Panels\PanelFormProjection;
use Proxyma\Contracts\Bindings\BindingCommands;
use Proxyma\Contracts\Bindings\BindingQueries;
use Proxyma\Contracts\Catalog\CatalogMutationFence;
use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\BindSubjectPolicyCommand;
use Proxyma\Domain\Policy\PolicyConflict;
use Proxyma\Domain\Policy\PolicyStorageFailure;
use Proxyma\Domain\Policy\SettingChange;
use Proxyma\Domain\Policy\SettingPatch;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Domain\Policy\UnbindSubjectPolicyCommand;
use Proxyma\Filament\BindingEditor;
use Proxyma\Filament\Pages\Policy\PolicyInspector;
use Proxyma\Filament\Tests\Support\WithBindingEditor;
use Proxyma\Filament\Tests\Support\WithFilament;
use Proxyma\Tests\Support\CatalogTestCase;

require_once __DIR__.'/../../core/tests/Support/CatalogTestCase.php';
require_once __DIR__.'/Support/WithFilament.php';
require_once __DIR__.'/Support/WithBindingEditor.php';

uses(CatalogTestCase::class, WithFilament::class, WithBindingEditor::class)->group('pg');

beforeEach(function (): void {
    $this->prepareBindings();
});

it('восстанавливает binding audit после health migration без ослабления allowlist', function (): void {
    $migration = require __DIR__.'/../../core/database/migrations/2026_11_10_000014_restore_binding_audit_type.php';
    $migration->down();
    $command = new BindSubjectPolicyCommand($this->document, null, 'default', $this->actor(), 'files', 'read');
    $before = $this->table('audit_events')->count();
    expect(fn () => app(BindingCommands::class)->bindPolicy($command))->toThrow(PolicyStorageFailure::class);
    expect($this->table('bindings')->count())->toBe(0)
        ->and($this->table('policy_revisions')->count())->toBe(0)
        ->and($this->table('audit_events')->count())->toBe($before);
    $migration->up();
    app(BindingCommands::class)->bindPolicy($command);
    expect($this->table('audit_events')->where('entity_type', 'bindings')->count())->toBe(1);
    $invalid = (array) $this->table('audit_events')->where('entity_type', 'bindings')->sole();
    $invalid['id'] = Uuid::random()->value;
    $invalid['idempotency_key'] = hash('sha256', $invalid['id']);
    $invalid['entity_type'] = 'arbitrary';
    $this->rejectedSql(fn () => $this->table('audit_events')->insert($invalid), '23514');
    expect(fn () => $migration->down())->toThrow(LogicException::class, 'Binding audit history');
});

it('различает отсутствующий override, set null, пустой список и inherit в typed форме', function (): void {
    $id = BindingEditor::fieldId('destinations.allowed');
    $page = $this->bindingPage()->assertSee('identifier_list')->assertSee('package_defaults@r1')
        ->assertDontSee('private.value')->assertDontSee('timeout.read_ms')->assertSet("data.fields.{$id}.mode", 'inherit');
    expect(app(BindingQueries::class)->findBinding($this->document, 'files', 'read'))->toBeNull();
    $page->set("data.fields.{$id}.mode", 'null')->set('data.reason', 'Явный null')->call('save')->assertHasNoErrors();
    $binding = app(BindingQueries::class)->findBinding($this->document, 'files', 'read');
    expect($binding->patch->changes)->toHaveKey('destinations.allowed')
        ->and($binding->patch->changes['destinations.allowed']->value)->toBeNull();
    $page->set("data.fields.{$id}.mode", 'set')->set("data.fields.{$id}.value", [])->set('data.reason', 'Запрет destinations')
        ->call('save')->assertHasNoErrors();
    expect(app(BindingQueries::class)->findBinding($this->document, 'files', 'read')->patch->changes['destinations.allowed']->value)->toBe([]);
    $page->set("data.fields.{$id}.mode", 'inherit')->set('data.reason', 'Снять override')->call('save')->assertHasNoErrors()
        ->assertSet("data.fields.{$id}.mode", 'inherit');
    expect(app(BindingQueries::class)->findBinding($this->document, 'files', 'read')->patch->changes)->not->toHaveKey('destinations.allowed');
});

it('сохраняет integer, boolean, identifier и typed lists без JSON редактора', function (): void {
    $page = $this->bindingPage();
    foreach (['timeout.connect_ms' => 400, 'route.allow_direct' => true, 'route.strategy' => 'least_recent_authorized',
        'routes.denied' => ['route.one'], 'retry.delays_ms' => [10, 30]] as $key => $value) {
        $id = BindingEditor::fieldId($key);
        $page->set("data.fields.{$id}.mode", 'set')->set("data.fields.{$id}.value", is_array($value) ? array_map(static fn (string|int $item): array => ['item' => $item], $value) : $value);
    }
    $page->set('data.reason', 'Типизированные overrides')->call('save')->assertHasNoErrors();
    $changes = app(BindingQueries::class)->findBinding($this->document, 'files', 'read')->patch->changes;
    expect($changes['timeout.connect_ms']->value)->toBe(400)->and($changes['route.allow_direct']->value)->toBeTrue()
        ->and($changes['route.strategy']->value)->toBe('least_recent_authorized')->and($changes['routes.denied']->value)->toBe(['route.one'])
        ->and($changes['retry.delays_ms']->value)->toBe([10, 30]);
    $id = BindingEditor::fieldId('retry.delays_ms');
    $page->set("data.fields.{$id}.mode", 'disable')->set('data.reason', 'Отключение retry delays')->call('save')->assertHasNoErrors();
    expect(app(BindingQueries::class)->findBinding($this->document, 'files', 'read')->patch->changes['retry.delays_ms']->operation->value)->toBe('disable');
});

it('отклоняет недопустимые операции, поля и типы атомарно', function (string $key, string $mode, mixed $value): void {
    $editor = app(BindingEditor::class);
    $data = $editor->open($this->document, $this->catalog['target'], 'files', 'read');
    $data['fields'][BindingEditor::fieldId($key)] = ['mode' => $mode, 'value' => $value];
    $data['reason'] = 'Невалидный patch';
    $before = $this->table('audit_events')->count();
    expect(fn () => $editor->save($data))->toThrow(ValidationException::class);
    expect($this->table('bindings')->count())->toBe(0)->and($this->table('policy_revisions')->count())->toBe(0)
        ->and($this->table('audit_events')->count())->toBe($before);
})->with([
    ['timeout.connect_ms', 'set', 0], ['timeout.connect_ms', 'set', '400'], ['timeout.connect_ms', 'null', null],
    ['timeout.connect_ms', 'disable', null], ['route.strategy', 'set', 'unregistered'], ['routes.denied', 'set', [42]],
    ['retry.delays_ms', 'set', [-1]], ['route.allow_direct', 'set', 1], ['destinations.allowed', 'unknown', null],
    ['timeout.read_ms', 'set', 50], ['private.value', 'set', ['hidden']], ['owner.only', 'set', true],
]);

it('показывает clamp и hard-bound lock, исключая недоступные scopes', function (): void {
    $this->reconfigureProxyma([
        'policy.hard_bounds' => ['route.allow_direct' => false, 'timeout.connect_ms' => ['max' => 500]],
        'policy.project_defaults' => ['timeout.connect_ms' => 800],
    ]);
    $projection = app(PanelFormProjection::class)->project($this->document, $this->catalog['target'], 'files', 'read');
    expect($projection->fields['timeout.connect_ms']['value'])->toBe(500)
        ->and($projection->fields['timeout.connect_ms']['clamped'])->toBeTrue()
        ->and($projection->fields['route.allow_direct']['editable'])->toBeFalse()
        ->and($projection->fields)->not->toHaveKey('owner.only');
    $this->bindingPage()->assertSee('clamp: yes')->assertDontSee('owner.only');
    $data = app(BindingEditor::class)->open($this->document, $this->catalog['target'], 'files', 'read');
    $data['fields'][BindingEditor::fieldId('route.allow_direct')] = ['mode' => 'set', 'value' => true];
    $data['reason'] = 'Обход hard bound';
    expect(fn () => app(BindingEditor::class)->save($data))->toThrow(AuthorizationDenied::class);
    expect($this->table('bindings')->count())->toBe(0);
});

it('повторяет permissions при сохранении и показывает безопасную причину запрета', function (): void {
    $page = $this->bindingPage();
    $this->permissions(fn ($a, $t, string $ability): bool => $ability !== 'policy.timeout.edit');
    $id = BindingEditor::fieldId('timeout.connect_ms');
    $data = $page->get('data');
    $data['fields'][$id] = ['mode' => 'set', 'value' => 400];
    $data['reason'] = 'Отозванные права';
    expect(fn () => app(BindingEditor::class)->save($data))->toThrow(AuthorizationDenied::class);
    $this->bindingPage()->assertSee('locked_by_permission');
    $this->permissions(fn ($a, $t, string $ability): bool => $ability !== 'binding.update');
    $this->bindingPage()->assertSee('permission_denied')->set('data.reason', 'Прямой вызов')->call('save')->assertForbidden();
    expect($this->table('bindings')->count())->toBe(0);
});

it('отклоняет stale leaf и изменение ancestor пока форма открыта', function (bool $ancestor): void {
    $page = $this->bindingPage();
    app(BindingCommands::class)->bindPolicy(new BindSubjectPolicyCommand($ancestor ? $this->folder : $this->document,
        null, 'default', $this->actor(), 'files', 'read', new SettingPatch(1, ['timeout.connect_ms' => SettingChange::set(300)])));
    $before = $this->table('audit_events')->count();
    $page->set('data.reason', 'Устаревшая форма')->call('save')->assertHasErrors(['data'])->assertSee('Перезагрузите форму');
    expect($this->table('bindings')->count())->toBe(1)->and($this->table('audit_events')->count())->toBe($before);
})->with([false, true]);

it('core command проверяет policy fence внутри transaction без partial writes', function (): void {
    $projection = app(PanelFormProjection::class)->project($this->document, $this->catalog['target'], 'files', 'read');
    app(BindingCommands::class)->bindPolicy(new BindSubjectPolicyCommand($this->folder, null, 'default', $this->actor(), 'files', 'read'));
    $before = [$this->table('bindings')->count(), $this->table('audit_events')->count(), $this->table('policy_revisions')->count()];
    expect(fn () => app(BindingCommands::class)->bindPolicy(new BindSubjectPolicyCommand($this->document, null, 'default', $this->actor(),
        'files', 'read', expectedPolicyVersion: $projection->editVersion)))->toThrow(PolicyConflict::class);
    expect([$this->table('bindings')->count(), $this->table('audit_events')->count(), $this->table('policy_revisions')->count()])->toBe($before);
});

it('показывает orphan причину и закрывает сохранение', function (): void {
    unset($this->subjects->chains[$this->folder->key()]);
    $page = $this->bindingPage()->assertSee('Orphan');
    $page->set('data.reason', 'Orphan')->call('save')->assertForbidden();
    expect($this->table('bindings')->count())->toBe(0);
});

it('показывает отсутствие обязательного explicit binding', function (): void {
    $this->bindingPage(slot: 'explicit')->assertSee('Explicit binding required.');
    expect($this->table('bindings')->count())->toBe(0);
});

it('отклоняет изменившуюся ancestry и исчезнувшего субъекта открытой формы', function (bool $missing): void {
    $editor = app(BindingEditor::class);
    $data = $editor->open($this->document, $this->catalog['target'], 'files', 'read');
    $data['reason'] = 'Изменение иерархии';
    if ($missing) {
        unset($this->subjects->chains[$this->document->key()]);
    } else {
        $this->subjects->chains[$this->document->key()] = new SubjectChain($this->document);
    }
    expect(fn () => $editor->save($data))->toThrow(ValidationException::class);
    expect($this->table('bindings')->count())->toBe(0);
})->with([false, true]);

it('сохраняет exact replay optimistic command после изменения policy fence', function (): void {
    $version = app(PanelFormProjection::class)->project($this->document, $this->catalog['target'], 'files', 'read')->editVersion;
    $command = new BindSubjectPolicyCommand($this->document, null, 'default', $this->actor(), 'files', 'read', expectedPolicyVersion: $version);
    $commands = app(BindingCommands::class);
    $first = $commands->bindPolicy($command);
    $commands->bindPolicy(new BindSubjectPolicyCommand($this->folder, null, 'default', $this->actor(), 'files', 'read'));
    expect($commands->bindPolicy($command)->revision)->toBe($first->revision)
        ->and($this->table('bindings')->count())->toBe(2)
        ->and($this->table('audit_events')->where('ability', 'binding.bind')->count())->toBe(2);
});

it('inspector показывает только разрешённые поля и запрещает прямой вызов save', function (): void {
    $this->bindingPage(PolicyInspector::class)->assertSee('package_defaults@r1')->assertDontSee('private.value')
        ->assertDontSee('Причина изменения')->call('save')->assertForbidden();
    expect($this->table('bindings')->count())->toBe(0);
});

it('возвращает retired binding к inherit без скрытого восстановления старого patch', function (): void {
    $commands = app(BindingCommands::class);
    $commands->bindPolicy(new BindSubjectPolicyCommand($this->document, null, 'default', $this->actor(), 'files', 'read',
        new SettingPatch(1, ['timeout.connect_ms' => SettingChange::set(300)])));
    $commands->unbindPolicy(new UnbindSubjectPolicyCommand($this->document, 'default', $this->actor(1), 'files', 'read'));
    $editor = app(BindingEditor::class);
    $state = $editor->open($this->document, $this->catalog['target'], 'files', 'read');
    expect($state['fields'][BindingEditor::fieldId('timeout.connect_ms')]['mode'])->toBe('inherit');
    $state['reason'] = 'Повторная привязка с наследованием';
    $editor->save($state);
    $binding = app(BindingQueries::class)->findBinding($this->document, 'files', 'read');
    expect($binding->retired)->toBeFalse()->and($binding->patch->changes)->toBe([]);
});

it('защищает контекст, server editing и tenant при прямом вызове', function (): void {
    $page = $this->bindingPage();
    expect(fn () => $page->set('editing.panel', 'archive'))->toThrow(CannotUpdateLockedPropertyException::class);
    $editor = app(BindingEditor::class);
    $data = $editor->open($this->document, $this->catalog['target'], 'files', 'read');
    $forged = $data;
    $forged['context'] .= 'tampered';
    expect(fn () => $editor->save($forged))->toThrow(AuthorizationDenied::class);
    $this->useTenant(new TenantKey('foreign'));
    expect(fn () => app(BindingEditor::class)->save($data))->toThrow(AuthorizationDenied::class);
    expect(fn () => app(BindingEditor::class)->open(new SubjectRef(new TenantKey('other'), 'document', '42'), $this->catalog['target'], 'files', 'read'))->toThrow(AuthorizationDenied::class);
    expect($this->table('bindings')->count())->toBe(0);
});

it('сохраняет opaque CatalogMutationFence token из open в save и отклоняет устаревший authority и подделку envelope', function (): void {
    $fence = new class implements CatalogMutationFence
    {
        public string $current = 'authority-v1';

        public ?string $capturedAuthority = null;

        public function version(): string
        {
            return $this->current;
        }

        public function lock(Connection $db, CatalogMutationActor $actor): void
        {
            $this->capturedAuthority = $actor->authorityVersion;

            if ($actor->authorityVersion !== $this->current) {
                throw new CatalogConflict('stale_authority');
            }
        }
    };

    $this->app->instance(CatalogMutationFence::class, $fence);
    $editor = app(BindingEditor::class);
    $opened = $editor->open($this->document, $this->catalog['target'], 'files', 'read');
    $opened['reason'] = 'Попытка после смены authority fence';

    $fence->current = 'authority-v2';

    expect(fn () => $editor->save($opened))->toThrow(ValidationException::class);
    expect($fence->capturedAuthority)->toBe('authority-v1')
        ->and($this->table('bindings')->count())->toBe(0);

    $missingAuthorityEnvelope = $opened;
    $missingAuthorityEnvelope['context'] = Crypt::encryptString(json_encode([
        'target' => $this->catalog['target']->value,
        'panel' => 'files',
        'inner' => 'fake-inner',
    ], JSON_THROW_ON_ERROR));
    expect(fn () => $editor->save($missingAuthorityEnvelope))->toThrow(AuthorizationDenied::class);

    $corruptedInner = $opened;
    $corruptedInner['context'] = Crypt::encryptString(json_encode([
        'target' => $this->catalog['target']->value,
        'panel' => 'files',
        'has_authority' => true,
        'authority_token' => 'authority-v1',
        'inner' => 'not-valid-packman-context',
    ], JSON_THROW_ON_ERROR));
    expect(fn () => $editor->save($corruptedInner))->toThrow(AuthorizationDenied::class);

    $fresh = $editor->open($this->document, $this->catalog['target'], 'files', 'read');
    $fresh['reason'] = 'Сохранение с текущим opaque token';
    $fresh['fields'][BindingEditor::fieldId('destinations.allowed')]['mode'] = 'null';
    $editor->save($fresh);
    expect($fence->capturedAuthority)->toBe('authority-v2')
        ->and($this->table('bindings')->count())->toBe(1);
});
