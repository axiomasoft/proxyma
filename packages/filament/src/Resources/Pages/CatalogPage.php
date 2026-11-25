<?php

declare(strict_types=1);

namespace Proxyma\Filament\Resources\Pages;

use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Packman\Filament\Concerns\Authorization\AuthorizesPage;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Catalog\CatalogConflict;
use Proxyma\Application\Catalog\CatalogNotFound;
use Proxyma\Contracts\Authorization\TenantContextResolver;
use Proxyma\Contracts\Catalog\CatalogMutationFence;
use Proxyma\Contracts\Catalog\CatalogQueries;
use Proxyma\Domain\Catalog\Endpoint;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Catalog\RouteProfile;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Filament\Actions\SaveCatalog;
use Proxyma\Filament\CatalogBrowser;
use UnitEnum;

/** @property-read Schema $form */
#[Api(since: '1.0')]
abstract class CatalogPage extends Page
{
    use AuthorizesPage;

    protected static string|UnitEnum|null $navigationGroup = 'Proxyma';

    protected string $view = 'proxyma-filament::catalog';

    protected static string $kind;

    /** @var array<string, mixed> */
    public array $data = [];

    #[Locked]
    public string $recordId;

    #[Locked]
    public int $revision = 0;

    #[Locked]
    public ?string $authorityVersion = null;

    #[Locked]
    public int $catalogPage = 1;

    #[Internal]
    public static function allowsDomainAccess(): bool
    {
        try {
            app(CatalogBrowser::class)->authorize(static::$kind);

            return true;
        } catch (AuthorizationDenied) {
            return false;
        }
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->newRecord();
    }

    public function newRecord(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->recordId = Uuid::random()->value;
        $this->revision = 0;
        $this->authorityVersion = app(CatalogMutationFence::class)->version();
        $this->resetErrorBag();
        $this->form->fill($this->defaults() + ['reason' => '']);
    }

    public function changePage(int $page): void
    {
        abort_unless(static::canAccess(), 403);
        $this->catalogPage = max(1, min(100000, $page));
    }

    #[Unexposed]
    public function canSave(string $operation = 'upsert'): bool
    {
        try {
            app(SaveCatalog::class)->authorize(static::$kind, $this->recordId, $operation);

            return true;
        } catch (AuthorizationDenied) {
            return false;
        }
    }

    public function save(): void
    {
        $this->guardWrite();
        try {
            $value = app(SaveCatalog::class)->save(static::$kind, $this->recordId, $this->revision, $this->form->getState(), $this->authorityVersion);
            $this->revision = $value->revision;
            $this->data['reason'] = '';
        } catch (CatalogConflict) {
            throw ValidationException::withMessages(['data' => 'Данные изменены другим оператором. Перезагрузите запись.']);
        } catch (CatalogNotFound) {
            abort(404);
        } catch (AuthorizationDenied) {
            abort(403);
        }
    }

    protected function guardWrite(string $operation = 'upsert'): void
    {
        abort_unless($this->canSave($operation), 403);
    }

    protected function load(string $id): Endpoint|Route|RouteProfile
    {
        try {
            $tenant = app(TenantContextResolver::class)->resolve()->tenant;
            $queries = app(CatalogQueries::class);
            $uuid = new Uuid($id);
            $value = match (static::$kind) {
                'endpoint' => $queries->findEndpoint($tenant, $uuid),
                'route' => $queries->findRoute($tenant, $uuid),
                'profile' => $queries->findProfile($tenant, $uuid),
                default => throw new InvalidArgumentException('Unknown catalog section.'),
            };
            abort_if($value === null || ! $value->tenantKey->equals($tenant), 404);
            $this->recordId = $id;
            $this->revision = $value->revision;
            $this->authorityVersion = app(CatalogMutationFence::class)->version();
            $this->resetErrorBag();

            return $value;
        } catch (AuthorizationDenied) {
            abort(403);
        }
    }

    protected function reason(): Textarea
    {
        return Textarea::make('reason')->label('Причина изменения')->required()->maxLength(512);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return ['records' => app(CatalogBrowser::class)->page(static::$kind, $this->catalogPage)];
    }

    /** @return array<string, mixed> */
    abstract protected function defaults(): array;

    abstract public function form(Schema $schema): Schema;
}
