<?php

declare(strict_types=1);

namespace Proxyma\Filament\Pages;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Livewire\Attributes\Locked;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Contracts\TenantContextResolver;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\PolicyScope;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Filament\BindingEditor;
use Proxyma\Filament\Schemas\ProxymaSettingsSection;
use UnitEnum;

/**
 * @property-read Schema $selector
 * @property-read Schema $form
 */
class BindingsPage extends Page
{
    protected string $view = 'proxyma-filament::bindings';

    protected static string|UnitEnum|null $navigationGroup = 'Proxyma';

    protected static ?string $navigationLabel = 'Настройки панелей';

    protected static bool $readOnly = false;

    public array $selection = [];

    public array $data = [];

    #[Locked]
    public array $editing = [];

    #[Unexposed]
    public static function canAccess(): bool
    {
        try {
            $tenant = app(TenantContextResolver::class)->resolve()->tenant;
            app(CatalogAccess::class)->authorize($tenant, 'policy.resolve', PolicyScope::Tenant->authorizationId($tenant, $tenant->value));

            return true;
        } catch (AuthorizationDenied) {
            return false;
        }
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->selector->fill();
    }

    public function selector(Schema $schema): Schema
    {
        $definitions = app(DefinitionRegistry::class);

        return $schema->components([
            Select::make('panel')->label('Панель')->options(array_combine(array_keys($definitions->panels()), array_keys($definitions->panels())))->required()->live(),
            Select::make('slot')->label('Slot')->options(fn ($get): array => isset($definitions->panels()[$get('panel')])
                ? array_combine(array_keys($definitions->panel($get('panel'))->slots), array_keys($definitions->panel($get('panel'))->slots)) : [])->required(),
            TextInput::make('type')->label('Тип субъекта')->required()->regex('/^[a-z][a-z0-9_.:-]{0,63}$/D'),
            TextInput::make('id')->label('ID субъекта')->required()->maxLength(255)->regex('/^[^\x00-\x20\x7f]+$/D'),
            TextInput::make('target')->label('Target UUID')->required()->uuid(),
        ])->statePath('selection');
    }

    public function loadBinding(): void
    {
        abort_unless(static::canAccess(), 403);
        $selection = $this->selector->getState();
        try {
            $tenant = app(TenantContextResolver::class)->resolve()->tenant;
            $subject = new SubjectRef($tenant, $selection['type'], $selection['id']);
            $target = new Uuid($selection['target']);
            $state = app(BindingEditor::class)->open($subject, $target, $selection['panel'], $selection['slot']);
            $this->editing = $selection;
            $this->editing['tenant'] = $tenant->value;
            $this->resetErrorBag();
            $this->cacheSchema('form', null);
            $this->form->fill($state);
        } catch (AuthorizationDenied) {
            abort(403);
        }
    }

    public function form(Schema $schema): Schema
    {
        if ($this->editing === []) {
            return $schema->components([])->statePath('data');
        }
        $selection = $this->editing;
        $subject = fn (): SubjectRef => new SubjectRef(new TenantKey($selection['tenant']), $selection['type'], $selection['id']);

        return $schema->components([ProxymaSettingsSection::make($subject, $selection['panel'], $selection['slot'], new Uuid($selection['target']), '', static::$readOnly)])->statePath('data');
    }

    public function save(): void
    {
        abort_if(static::$readOnly || $this->editing === [], 403);
        try {
            app(BindingEditor::class)->save($this->form->getState());
            $this->loadBinding();
        } catch (AuthorizationDenied) {
            abort(403);
        }
    }

    #[Unexposed]
    public function isReadOnly(): bool
    {
        return static::$readOnly;
    }
}
