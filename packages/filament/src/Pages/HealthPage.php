<?php

declare(strict_types=1);

namespace Proxyma\Filament\Pages;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Packman\Attributes\Api;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Health\ResetHealth;
use Proxyma\Application\Health\ReviewedProbe;
use Proxyma\Contracts\Authorization\TenantContextResolver;
use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\HealthScope;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Filament\Internal\FilamentValue;

/**
 * @property-read Schema $probeSelector
 * @property-read Schema $probeForm
 */
#[Api(since: '1.0')]
class HealthPage extends OperationsPage
{
    protected string $view = 'proxyma-filament::filament.pages.health-page';

    protected static string $kind = 'health';

    protected static ?string $navigationLabel = 'Здоровье маршрутов';

    #[Locked]
    public string $healthScope = 'route_health';

    /** @var array<string, mixed> */
    #[Locked]
    public array $probeReview = [];

    /** @var array<string, mixed> */
    #[Locked]
    public array $probeResult = [];

    /** @var array<string, mixed> */
    public array $probeSelection = [];

    /** @var array<string, mixed> */
    public array $probeData = [];

    public function mount(): void
    {
        parent::mount();
        $this->probeSelector->fill();
    }

    public function chooseScope(string $scope): void
    {
        $this->command(function () use ($scope): void {
            $this->healthScope = (HealthScope::tryFrom($scope) ?? throw new InvalidArgumentException('Unknown health scope.'))->value;
            $this->operationsPage = 1;
            $this->editing = [];
        });
    }

    public function resetHealth(): void
    {
        $this->command(function (): void {
            $this->assertEditing();
            abort_unless($this->canCommand('health.reset'), 403);
            $id = FilamentValue::str($this->editing['id'] ?? null);
            app(ResetHealth::class)->handle(HealthScope::from(FilamentValue::str($this->editing['scope'] ?? null)), new Uuid($id),
                app(TenantContextResolver::class)->resolve()->tenant, $this->actor($this->form->getState()));
            $this->edit($id);
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
            $review = app(ReviewedProbe::class)->review(
                new SubjectChain(new SubjectRef($tenant, FilamentValue::str($selection['subject_type'] ?? null), FilamentValue::str($selection['subject_id'] ?? null))),
                new Uuid(FilamentValue::str($selection['profile'] ?? null)),
                new Uuid(FilamentValue::str($selection['route'] ?? null)),
                new Uuid(FilamentValue::str($selection['target'] ?? null)),
            );
            $this->probeReview = $review + $selection + ['tenant' => $tenant->value, 'key' => Uuid::random()->value];
            $this->probeResult = [];
            $this->probeForm->fill(['reason' => '']);
        });
    }

    #[Unexposed]
    public function canProbe(): bool
    {
        if ($this->probeReview === [] || FilamentValue::nullableStr($this->probeReview['tenant'] ?? null) !== app(TenantContextResolver::class)->resolve()->tenant->value) {
            return false;
        }
        try {
            app(CatalogAccess::class)->authorize(app(TenantContextResolver::class)->resolve()->tenant, 'health.probe.execute', new Uuid(FilamentValue::str($this->probeReview['route'] ?? null)));

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
            $versionsRaw = FilamentValue::assoc($review['versions'] ?? []);
            $versions = [];
            foreach ($versionsRaw as $k => $v) {
                $versions[$k] = is_int($v) ? $v : FilamentValue::str($v);
            }
            $epochsRaw = FilamentValue::assoc($review['epochs'] ?? []);
            $epochs = [];
            foreach ($epochsRaw as $k => $v) {
                $epochs[$k] = FilamentValue::int($v);
            }
            $this->probeResult = app(ReviewedProbe::class)->execute(
                new SubjectChain(new SubjectRef($context->tenant, FilamentValue::str($review['subject_type'] ?? null), FilamentValue::str($review['subject_id'] ?? null))),
                new Uuid(FilamentValue::str($review['profile'] ?? null)),
                new Uuid(FilamentValue::str($review['route'] ?? null)),
                new Uuid(FilamentValue::str($review['target'] ?? null)),
                $versions,
                $epochs,
                new CatalogMutationActor($context->actor, FilamentValue::str($state['reason'] ?? null), FilamentValue::int($versions['policy'] ?? null), FilamentValue::str($review['key'] ?? null)),
            );
        });
    }

    protected function selectedHealthScope(): HealthScope
    {
        return HealthScope::from($this->healthScope);
    }
}
