<?php

declare(strict_types=1);

namespace Proxyma\Filament\Pages;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Livewire\Attributes\Locked;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Health\ResetHealth;
use Proxyma\Application\Health\ReviewedProbe;
use Proxyma\Contracts\TenantContextResolver;
use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\HealthScope;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\SubjectRef;

class HealthPage extends OperationsPage
{
    protected string $view = 'proxyma-filament::filament.pages.health-page';

    protected static string $kind = 'health';

    protected static ?string $navigationLabel = 'Здоровье маршрутов';

    #[Locked]
    public string $healthScope = 'route_health';

    #[Locked]
    public array $probeReview = [];

    #[Locked]
    public array $probeResult = [];

    public array $probeSelection = [];

    public array $probeData = [];

    public function mount(): void
    {
        parent::mount();
        $this->probeSelector->fill();
    }

    public function chooseScope(string $scope): void
    {
        $this->command(function () use ($scope): void {
            $this->healthScope = (HealthScope::tryFrom($scope) ?? throw new \InvalidArgumentException('Unknown health scope.'))->value;
            $this->operationsPage = 1;
            $this->editing = [];
        });
    }

    public function resetHealth(): void
    {
        $this->command(function (): void {
            $this->assertEditing();
            abort_unless($this->canCommand('health.reset'), 403);
            app(ResetHealth::class)->handle(HealthScope::from($this->editing['scope']), new Uuid($this->editing['id']),
                app(TenantContextResolver::class)->resolve()->tenant, $this->actor($this->form->getState()));
            $this->edit($this->editing['id']);
        });
    }

    public function probeSelector(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('profile')->label('Profile UUID')->required()->uuid(),
            TextInput::make('route')->label('Route UUID')->required()->uuid(),
            TextInput::make('target')->label('Target UUID')->required()->uuid(),
            TextInput::make('subject_type')->label('Тип субъекта')->default('operator')->required()->regex('/^[a-z][a-z0-9_.:-]{0,63}$/D'),
            TextInput::make('subject_id')->label('ID субъекта')->default('probe')->required()->maxLength(255),
        ])->statePath('probeSelection');
    }

    public function probeForm(Schema $schema): Schema
    {
        return $schema->components([Textarea::make('reason')->label('Причина probe')->required()->maxLength(512)])->statePath('probeData');
    }

    public function previewProbe(): void
    {
        $this->command(function (): void {
            $selection = $this->probeSelector->getState();
            $tenant = app(TenantContextResolver::class)->resolve()->tenant;
            $review = app(ReviewedProbe::class)->review(new SubjectChain(new SubjectRef($tenant, $selection['subject_type'], $selection['subject_id'])),
                new Uuid($selection['profile']), new Uuid($selection['route']), new Uuid($selection['target']));
            $this->probeReview = $review + $selection + ['tenant' => $tenant->value, 'key' => Uuid::random()->value];
            $this->probeResult = [];
            $this->probeForm->fill(['reason' => '']);
        });
    }

    #[Unexposed]
    public function canProbe(): bool
    {
        if ($this->probeReview === [] || $this->probeReview['tenant'] !== app(TenantContextResolver::class)->resolve()->tenant->value) {
            return false;
        }
        try {
            app(CatalogAccess::class)->authorize(app(TenantContextResolver::class)->resolve()->tenant, 'health.probe.execute', new Uuid($this->probeReview['route']));

            return true;
        } catch (AuthorizationDenied) {
            return false;
        }
    }

    public function probe(): void
    {
        $this->command(function (): void {
            abort_unless($this->canProbe(), 403);
            $review = $this->probeReview;
            $context = app(TenantContextResolver::class)->resolve();
            $state = $this->probeForm->getState();
            $this->probeResult = app(ReviewedProbe::class)->execute(new SubjectChain(new SubjectRef($context->tenant, $review['subject_type'], $review['subject_id'])),
                new Uuid($review['profile']), new Uuid($review['route']), new Uuid($review['target']), $review['versions'], $review['epochs'],
                new CatalogMutationActor($context->actor, $state['reason'], $review['versions']['policy'], $review['key']));
        });
    }

    protected function selectedHealthScope(): HealthScope
    {
        return HealthScope::from($this->healthScope);
    }
}
