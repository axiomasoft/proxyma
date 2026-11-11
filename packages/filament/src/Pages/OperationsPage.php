<?php

declare(strict_types=1);

namespace Proxyma\Filament\Pages;

use Closure;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Catalog\CatalogConflict;
use Proxyma\Contracts\CatalogMutationFence;
use Proxyma\Contracts\TenantContextResolver;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\HealthScope;
use Proxyma\Filament\Queries\OperationsQuery;
use UnitEnum;

/** @property-read Schema $form */
abstract class OperationsPage extends Page
{
    protected static string|UnitEnum|null $navigationGroup = 'Proxyma';

    protected static string $kind;

    #[Locked]
    public int $operationsPage = 1;

    #[Locked]
    public array $editing = [];

    public array $data = [];

    #[Unexposed]
    public static function canAccess(): bool
    {
        try {
            app(OperationsQuery::class)->authorize(static::$kind);

            return true;
        } catch (AuthorizationDenied) {
            return false;
        }
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function changePage(int $page): void
    {
        abort_unless(static::canAccess(), 403);
        $this->operationsPage = max(1, min(100000, $page));
    }

    public function edit(string $id): void
    {
        $this->command(function () use ($id): void {
            $row = app(OperationsQuery::class)->find(static::$kind, $id, $this->selectedHealthScope());
            abort_if($row === null, 404);
            $this->editing = $row + ['tenant' => app(TenantContextResolver::class)->resolve()->tenant->value, 'key' => Uuid::random()->value,
                'authority_version' => app(CatalogMutationFence::class)->version()];
            $this->form->fill($this->editState($row) + ['reason' => '']);
        });
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([Textarea::make('reason')->label('Причина команды')->required()->maxLength(512)])->statePath('data');
    }

    #[Unexposed]
    public function canCommand(string $ability): bool
    {
        if ($this->editing === []) {
            return false;
        }
        try {
            $this->assertEditing();
            app(CatalogAccess::class)->authorize(app(TenantContextResolver::class)->resolve()->tenant, $ability, new Uuid($this->editing['id']));

            return true;
        } catch (AuthorizationDenied) {
            return false;
        }
    }

    protected function actor(array $state): CatalogMutationActor
    {
        $this->assertEditing();

        return new CatalogMutationActor(app(TenantContextResolver::class)->resolve()->actor, $state['reason'],
            (int) ($this->editing['revision'] ?? $this->editing['state_version']), $this->editing['key'], $this->editing['authority_version'] ?? null);
    }

    protected function assertEditing(): void
    {
        if ($this->editing === [] || ($this->editing['tenant'] ?? null) !== app(TenantContextResolver::class)->resolve()->tenant->value) {
            throw new AuthorizationDenied;
        }
        app(OperationsQuery::class)->authorize(static::$kind);
        app(OperationsQuery::class)->authorize(static::$kind, $this->editing['id']);
    }

    protected function command(Closure $command): void
    {
        try {
            abort_unless(static::canAccess(), 403);
            $command();
        } catch (AuthorizationDenied) {
            abort(403);
        } catch (CatalogConflict|AdmissionRejected|InvalidArgumentException) {
            throw ValidationException::withMessages(['data' => 'Команда отклонена: состояние, область или ревизия изменились. Загрузите данные заново.']);
        }
    }

    protected function selectedHealthScope(): HealthScope
    {
        return HealthScope::Route;
    }

    protected function editState(array $row): array
    {
        return [];
    }

    protected function getViewData(): array
    {
        return ['records' => app(OperationsQuery::class)->page(static::$kind, $this->operationsPage, $this->selectedHealthScope())];
    }
}
