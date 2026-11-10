<?php

declare(strict_types=1);

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Livewire;
use Proxyma\Contracts\BindingCommands;
use Proxyma\Contracts\BindingQueries;
use Proxyma\Contracts\TenantContextResolver;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\BindSubjectPolicyCommand;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Filament\Actions\ConfigureProxymaAction;
use Proxyma\Filament\BindingEditor;
use Proxyma\Filament\Schemas\ProxymaSettingsSection;
use Proxyma\Filament\Tests\Support\WithBindingEditor;
use Proxyma\Filament\Tests\Support\WithFilament;
use Proxyma\Tests\Support\CatalogTestCase;

require_once __DIR__.'/../../core/tests/Support/CatalogTestCase.php';
require_once __DIR__.'/Support/WithFilament.php';
require_once __DIR__.'/Support/WithBindingEditor.php';

uses(CatalogTestCase::class, WithFilament::class, WithBindingEditor::class);

class EmbeddedProxymaEditor extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public array $data = [];

    public ?string $savedOwnerSchedule = null;

    #[Locked]
    public string $panel;

    #[Locked]
    public string $slot;

    #[Locked]
    public string $type;

    #[Locked]
    public string $subjectId;

    #[Locked]
    public string $target;

    public function mount(string $target, string $panel = 'files', string $slot = 'read', string $type = 'document', string $subjectId = '42'): void
    {
        $this->target = $target;
        $this->panel = $panel;
        $this->slot = $slot;
        $this->type = $type;
        $this->subjectId = $subjectId;
        $this->form->fill();
    }

    protected function subject(): SubjectRef
    {
        return new SubjectRef(app(TenantContextResolver::class)->resolve()->tenant, $this->type, $this->subjectId);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('schedule')->label('Расписание владельца')->default('tomorrow'),
            ProxymaSettingsSection::make(fn (): SubjectRef => $this->subject(), $this->panel, $this->slot, new Uuid($this->target)),
        ])->statePath('data');
    }

    public function configureProxymaAction(): Action
    {
        return ConfigureProxymaAction::make(fn (): SubjectRef => $this->subject(), $this->panel, $this->slot, new Uuid($this->target));
    }

    public function save(): void
    {
        $state = $this->form->getState();
        app(BindingEditor::class)->save($state['proxyma']);
        $this->savedOwnerSchedule = $state['schedule'];
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}{{ $this->configureProxymaAction }}<x-filament-actions::modals /></div>';
    }
}

beforeEach(function (): void {
    $this->prepareBindings();
});

it('переиспользует section для двух panels subjects slots и оставляет scheduling владельцу', function (): void {
    foreach ([['files', 'read', $this->document, 400], ['archive', 'write', $this->folder, 300]] as [$panel, $slot, $subject, $value]) {
        $page = Livewire::test(EmbeddedProxymaEditor::class, ['target' => $this->catalog['target']->value, 'panel' => $panel,
            'slot' => $slot, 'type' => $subject->type, 'subjectId' => $subject->id]);
        $id = BindingEditor::fieldId('timeout.connect_ms');
        $page->assertSee('Расписание владельца')->set("data.proxyma.fields.{$id}.mode", 'set')
            ->set("data.proxyma.fields.{$id}.value", $value)->set('data.proxyma.reason', 'Встроенная section')
            ->set('data.schedule', 'next-week')->call('save')->assertHasNoErrors()->assertSet('savedOwnerSchedule', 'next-week');
        $binding = app(BindingQueries::class)->findBinding($subject, $panel, $slot);
        expect($binding->patch->changes['timeout.connect_ms']->value)->toBe($value)
            ->and($binding->patch->changes)->not->toHaveKey('schedule');
    }
    expect(app(BindingQueries::class)->findBinding($this->folder, 'files', 'write'))->toBeNull()
        ->and(app(BindingQueries::class)->findBinding($this->document, 'archive', 'read'))->toBeNull()
        ->and($this->table('bindings')->count())->toBe(2);
});

it('переиспользует modal action с тем же command и не сохраняет siblings', function (): void {
    $page = Livewire::test(EmbeddedProxymaEditor::class, ['target' => $this->catalog['target']->value, 'panel' => 'archive', 'slot' => 'write']);
    $id = BindingEditor::fieldId('timeout.connect_ms');
    $page->mountAction('configureProxyma')->assertSee('timeout.connect_ms')
        ->set("mountedActions.0.data.proxyma.fields.{$id}.mode", 'set')
        ->set("mountedActions.0.data.proxyma.fields.{$id}.value", 250)
        ->set('mountedActions.0.data.proxyma.reason', 'Modal action')->callMountedAction()->assertHasNoErrors()
        ->assertSet('savedOwnerSchedule', null)->assertSet('data.schedule', 'tomorrow');
    expect(app(BindingQueries::class)->findBinding($this->document, 'archive', 'write')->patch->changes['timeout.connect_ms']->value)->toBe(250)
        ->and($this->table('audit_events')->where('ability', 'binding.bind')->count())->toBe(1);
});

it('action не меняет binding при stale ancestor и не сохраняет scheduling', function (): void {
    $page = Livewire::test(EmbeddedProxymaEditor::class, ['target' => $this->catalog['target']->value]);
    $page->mountAction('configureProxyma');
    app(BindingCommands::class)->bindPolicy(new BindSubjectPolicyCommand(
        $this->folder, null, 'default', $this->actor(), 'files', 'read'));
    $page->set('mountedActions.0.data.proxyma.reason', 'Stale modal')->callMountedAction()->assertHasErrors(['data'])
        ->assertSet('savedOwnerSchedule', null);
    expect(app(BindingQueries::class)->findBinding($this->document, 'files', 'read'))->toBeNull();
});
