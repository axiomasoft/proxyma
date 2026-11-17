<?php

declare(strict_types=1);

namespace Proxyma\Filament;

use Filament\Panel;
use LogicException;
use Override;
use Packman\Attributes\Api;
use Packman\Filament\Contracts\FilamentPanelPlugin;
use Proxyma\Filament\Pages\AttemptsPage;
use Proxyma\Filament\Pages\AuditPage;
use Proxyma\Filament\Pages\BindingsPage;
use Proxyma\Filament\Pages\BudgetsPage;
use Proxyma\Filament\Pages\HealthPage;
use Proxyma\Filament\Pages\PolicyInspector;
use Proxyma\Filament\Resources\Pages\Endpoints;
use Proxyma\Filament\Resources\Pages\RouteProfiles;
use Proxyma\Filament\Resources\Pages\Routes;

/**
 * Filament panel plugin registering Proxyma administration pages and Packman panel enforcement metadata.
 */
#[Api(since: '1.0')]
class ProxymaPlugin implements FilamentPanelPlugin
{
    private ?string $guardPanel = null;

    private bool $enforced = false;

    /** @var array{resources: list<string>, pages: list<string>, widgets: list<string>} */
    private array $exclude = [
        'resources' => [],
        'pages' => [],
        'widgets' => [],
    ];

    /** @var list<string> */
    private array $abilities = ['view', 'update', 'execute'];

    public static function make(): static
    {
        $plugin = app(static::class);

        if (! $plugin instanceof static) {
            throw new LogicException('Expected instance of '.static::class.'.');
        }

        return $plugin;
    }

    #[Override]
    public function getId(): string
    {
        return 'proxyma';
    }

    public function guardPanel(?string $panel): static
    {
        $this->guardPanel = $panel;

        return $this;
    }

    #[Override]
    public function getGuardPanel(): ?string
    {
        return $this->guardPanel;
    }

    public function enforce(bool $enforced = true): static
    {
        $this->enforced = $enforced;

        return $this;
    }

    #[Override]
    public function isEnforced(): bool
    {
        return $this->enforced;
    }

    /** @param array{resources?: list<string>, pages?: list<string>, widgets?: list<string>} $exclude */
    public function exclude(array $exclude): static
    {
        $this->exclude = [
            'resources' => $exclude['resources'] ?? [],
            'pages' => $exclude['pages'] ?? [],
            'widgets' => $exclude['widgets'] ?? [],
        ];

        return $this;
    }

    /** @return array{resources: list<string>, pages: list<string>, widgets: list<string>} */
    #[Override]
    public function getExclude(): array
    {
        return $this->exclude;
    }

    /** @param list<string> $abilities */
    public function abilities(array $abilities): static
    {
        $this->abilities = $abilities;

        return $this;
    }

    /** @return list<string> */
    #[Override]
    public function getAbilities(): array
    {
        return $this->abilities;
    }

    #[Override]
    public function register(Panel $panel): void
    {
        $panel->pages([
            Endpoints::class,
            Routes::class,
            RouteProfiles::class,
            BindingsPage::class,
            PolicyInspector::class,
            BudgetsPage::class,
            HealthPage::class,
            AttemptsPage::class,
            AuditPage::class,
        ]);
    }

    #[Override]
    public function boot(Panel $panel): void {}
}
