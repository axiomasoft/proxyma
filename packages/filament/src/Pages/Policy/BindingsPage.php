<?php

declare(strict_types=1);

namespace Proxyma\Filament\Pages\Policy;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Livewire\Attributes\Locked;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Packman\Filament\Concerns\Authorization\AuthorizesPage;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Contracts\Authorization\TenantContextResolver;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\PolicyScope;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Filament\BindingEditor;
use Proxyma\Filament\Internal\FilamentValue;
use Proxyma\Filament\Schemas\ProxymaSettingsSection;
use UnitEnum;

/**
 * @property-read Schema $selector
 * @property-read Schema $form
 */
#[Api(since: '1.0')]
class BindingsPage extends Page
{
    use AuthorizesPage;

    protected string $view = 'proxyma-filament::bindings';

    protected static string|UnitEnum|null $navigationGroup = 'Proxyma';

    protected static ?string $navigationLabel = 'Настройки панелей';

    protected static bool $readOnly = false;

    /** @var array<string, mixed> */
    public array $selection = [];

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, mixed> */
    #[Locked]
    public array $editing = [];

    #[Internal]
    public static function allowsDomainAccess(): bool
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

        $panelKeys = array_keys($definitions->panels());

        return $schema->components([
            Select::make('panel')->label('Панель')->options(array_combine($panelKeys, $panelKeys))->required()->live(),
            Select::make('slot')->label('Slot')->options(function (Get $get) use ($definitions): array {
                $panelKey = FilamentValue::nullableStr($get('panel'));
                if ($panelKey === null || ! isset($definitions->panels()[$panelKey])) {
                    return [];
                }
                $slots = array_keys($definitions->panel($panelKey)->slots);

                return array_combine($slots, $slots);
            })->required(),
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
            $subject = new SubjectRef($tenant, FilamentValue::str($selection['type'] ?? null), FilamentValue::str($selection['id'] ?? null));
            $target = new Uuid(FilamentValue::str($selection['target'] ?? null));
            $state = app(BindingEditor::class)->open($subject, $target, FilamentValue::str($selection['panel'] ?? null), FilamentValue::str($selection['slot'] ?? null));
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
        $subject = fn (): SubjectRef => new SubjectRef(
            new TenantKey(FilamentValue::str($selection['tenant'] ?? null)),
            FilamentValue::str($selection['type'] ?? null),
            FilamentValue::str($selection['id'] ?? null),
        );

        return $schema->components([
            ProxymaSettingsSection::make(
                $subject,
                FilamentValue::str($selection['panel'] ?? null),
                FilamentValue::str($selection['slot'] ?? null),
                new Uuid(FilamentValue::str($selection['target'] ?? null)),
                '',
                static::$readOnly,
            ),
        ])->statePath('data');
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
