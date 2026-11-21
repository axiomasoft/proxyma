<?php

declare(strict_types=1);

namespace Proxyma\Filament\Internal;

use BackedEnum;
use Closure;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource as FilamentResource;
use Filament\Widgets\Widget;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Override;
use Packman\Attributes\Internal;
use Packman\Context\Contracts\ContextResolverInterface;
use Packman\Context\Data\ScopeRef;
use Packman\Context\Exceptions\ScopeUnavailableException;
use Packman\Filament\Contracts\FilamentPanelPlugin;
use Packman\Filament\Contracts\FilamentSurfaceGate;
use Packman\Filament\Enums\FilamentErrorCode;
use Packman\Panels\Contracts\Admission\PanelAdmitter;
use Packman\Panels\Contracts\Registry\PanelRegistry;
use Packman\Panels\Enums\Admission\TenantMode;
use Proxyma\Filament\Pages\Operations\OperationsPage;
use Proxyma\Filament\Pages\Policy\BindingsPage;
use Proxyma\Filament\Resources\Pages\CatalogPage;
use Throwable;
use UnitEnum;

/**
 * Decorates Packman's `FilamentSurfaceGate` so Proxyma's `AuthorizesPage` pages honor host Gates,
 * `->enforce()`, `guardPanel()`, and Proxyma's domain `Authorizer`.
 */
#[Internal]
final readonly class ProxymaSurfaceGate implements FilamentSurfaceGate
{
    public function __construct(
        private FilamentSurfaceGate $inner,
        private Gate $gate,
        private ?Container $container = null,
    ) {}

    /**
     * @param  class-string<FilamentResource>  $resource
     */
    #[Override]
    public function authorizeResource(
        string $resource,
        string|UnitEnum $action,
        ?Model $record,
        FilamentPanelPlugin $plugin,
    ): Response {
        return $this->inner->authorizeResource($resource, $action, $record, $plugin);
    }

    /**
     * @param  class-string<RelationManager>  $relationManager
     * @param  (Closure(): Response)|null  $otherwise
     */
    #[Override]
    public function authorizeRelationManager(
        string $relationManager,
        string|UnitEnum $action,
        ?Model $record,
        FilamentPanelPlugin $plugin,
        ?Closure $otherwise = null,
    ): Response {
        return $this->inner->authorizeRelationManager($relationManager, $action, $record, $plugin, $otherwise);
    }

    /**
     * @param  class-string<Page>  $page
     * @param  (Closure(): Response)|null  $otherwise
     */
    #[Override]
    public function authorizePage(
        string $page,
        FilamentPanelPlugin $plugin,
        ?Closure $otherwise = null,
    ): Response {
        if (! self::isProxymaPage($page)) {
            return $this->inner->authorizePage($page, $plugin, $otherwise);
        }

        if ($plugin->isEnforced() && ! self::isExcludedPage($page, $plugin)) {
            $user = Filament::auth()->user();

            if ($user === null || ! $this->guardPanelAllows($plugin, $user)) {
                return Response::deny('Forbidden', FilamentErrorCode::AuthorizationDenied->value)->withStatus(403);
            }

            $hostGate = $this->inner->authorizePage(
                $page,
                self::unenforcedPluginProxy($plugin),
                static fn (): Response => Response::allow('__proxyma_no_gate__'),
            );

            if (! $hostGate->allowed()) {
                return $hostGate;
            }

            return self::domainResponse($page, $otherwise);
        }

        $inner = $this->inner->authorizePage(
            $page,
            $plugin,
            static fn (): Response => Response::allow('__proxyma_fallback__'),
        );

        if (! $inner->allowed()) {
            return $inner;
        }

        return self::domainResponse($page, $otherwise);
    }

    /**
     * @param  class-string<Widget>  $widget
     * @param  (Closure(): Response)|null  $otherwise
     */
    #[Override]
    public function authorizeWidget(
        string $widget,
        FilamentPanelPlugin $plugin,
        ?Closure $otherwise = null,
    ): Response {
        return $this->inner->authorizeWidget($widget, $plugin, $otherwise);
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<FilamentResource>  $resource
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    #[Override]
    public function scopeVisibleQuery(
        string $resource,
        Builder $query,
        FilamentPanelPlugin $plugin,
    ): Builder {
        return $this->inner->scopeVisibleQuery($resource, $query, $plugin);
    }

    /**
     * @param  array<mixed>  $arguments
     */
    #[Override]
    public function beforeGate(
        ?object $user,
        string $ability,
        array $arguments,
        FilamentPanelPlugin $plugin,
    ): ?bool {
        return $this->inner->beforeGate($user, $ability, $arguments, $plugin);
    }

    /**
     * @param  class-string  $relationManager
     */
    #[Override]
    public function resolveRelationAbility(
        string $relationManager,
        FilamentPanelPlugin $plugin,
    ): ?string {
        return $this->inner->resolveRelationAbility($relationManager, $plugin);
    }

    #[Override]
    public function canExport(FilamentPanelPlugin $plugin): bool
    {
        return $this->inner->canExport($plugin);
    }

    /**
     * @param  class-string<Page>  $page
     */
    private static function isProxymaPage(string $page): bool
    {
        return is_a($page, CatalogPage::class, true)
            || is_a($page, OperationsPage::class, true)
            || is_a($page, BindingsPage::class, true);
    }

    /**
     * @param  class-string<Page>  $page
     */
    private static function isExcludedPage(string $page, FilamentPanelPlugin $plugin): bool
    {
        return in_array($page, $plugin->getExclude()['pages'], true);
    }

    /**
     * @param  class-string<Page>  $page
     * @param  (Closure(): Response)|null  $otherwise
     */
    private static function domainResponse(string $page, ?Closure $otherwise): Response
    {
        $allowed = match (true) {
            is_a($page, CatalogPage::class, true) => $page::allowsDomainAccess(),
            is_a($page, OperationsPage::class, true) => $page::allowsDomainAccess(),
            is_a($page, BindingsPage::class, true) => $page::allowsDomainAccess(),
            default => false,
        };

        if (! $allowed) {
            return Response::deny('Forbidden', FilamentErrorCode::AuthorizationDenied->value)->withStatus(403);
        }

        return ($otherwise ?? Response::allow(...))();
    }

    private function guardPanelAllows(FilamentPanelPlugin $plugin, object $user): bool
    {
        $guardPanelId = $plugin->getGuardPanel();

        if ($guardPanelId === null) {
            return true;
        }

        if ($this->container === null || ! $this->container->bound(PanelRegistry::class)) {
            return false;
        }

        $panels = $this->container->make(PanelRegistry::class);

        try {
            $panel = $panels->find($guardPanelId);
        } catch (Throwable) {
            return false;
        }

        if ($panel === null) {
            return false;
        }

        $entryPermission = $panel->entry();

        if ($entryPermission !== null) {
            $key = match (true) {
                is_string($entryPermission) => $entryPermission,
                $entryPermission instanceof BackedEnum => (string) $entryPermission->value,
                default => $entryPermission->name,
            };

            if (! $this->gate->has($key) || $this->gate->forUser($user)->denies($key)) {
                return false;
            }
        }

        $requiresAdmission = $panel->subjectModels() !== [] || $panel->tenantMode() === TenantMode::Required;

        if (! $requiresAdmission) {
            return true;
        }

        if (! $this->container->bound(PanelAdmitter::class)) {
            return false;
        }

        $admission = $this->container->make(PanelAdmitter::class);
        $scope = $this->resolveScope();

        if ($panel->tenantMode() === TenantMode::Required && in_array($scope->id(), ['global', 'system', ''], true)) {
            return false;
        }

        return $admission->admit($panel, $user, $scope) !== null;
    }

    private function resolveScope(): ScopeRef
    {
        if ($this->container === null || ! $this->container->bound(ContextResolverInterface::class)) {
            return new ScopeRef('global');
        }

        $resolver = $this->container->make(ContextResolverInterface::class);

        try {
            $resolved = $resolver->current();

            if ($resolved === null) {
                return new ScopeRef('global');
            }

            return $resolved instanceof ScopeRef ? $resolved : new ScopeRef($resolved->id());
        } catch (ScopeUnavailableException) {
            return new ScopeRef('global');
        }
    }

    private static function unenforcedPluginProxy(FilamentPanelPlugin $plugin): FilamentPanelPlugin
    {
        return new readonly class($plugin) implements FilamentPanelPlugin
        {
            public function __construct(private FilamentPanelPlugin $inner) {}

            #[Override]
            public function getId(): string
            {
                return $this->inner->getId();
            }

            #[Override]
            public function register(Panel $panel): void
            {
                $this->inner->register($panel);
            }

            #[Override]
            public function boot(Panel $panel): void
            {
                $this->inner->boot($panel);
            }

            #[Override]
            public function getGuardPanel(): ?string
            {
                return $this->inner->getGuardPanel();
            }

            #[Override]
            public function isEnforced(): bool
            {
                return false;
            }

            /** @return array{resources: list<string>, pages: list<string>, widgets: list<string>} */
            #[Override]
            public function getExclude(): array
            {
                return $this->inner->getExclude();
            }

            /** @return list<string> */
            #[Override]
            public function getAbilities(): array
            {
                return $this->inner->getAbilities();
            }
        };
    }
}
