<?php

declare(strict_types=1);

namespace Proxyma\Filament\Tests\Support;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User;
use Livewire\LivewireServiceProvider;
use Packman\Filament\PackmanFilamentServiceProvider;
use Packman\PackmanServiceProvider;
use Packman\Panels\Laravel\PanelsServiceProvider;
use Packman\Plugins\Laravel\PluginsServiceProvider;
use Proxyma\Domain\Catalog\Endpoint;
use Proxyma\Domain\Catalog\ProxyProtocol;
use Proxyma\Domain\Catalog\UpsertEndpointCommand;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Filament\ProxymaFilamentServiceProvider;
use Proxyma\Filament\ProxymaPlugin;

class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->default()->id('proxyma-test')->path('proxyma-test')->plugin(ProxymaPlugin::make());
    }
}

trait WithFilament
{
    /** @param Application $app */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        // В runner исходники и vendor доступны только для чтения.
        $app['config']->set('view.compiled', sys_get_temp_dir().'/proxyma-filament-views-'.getmypid());
    }

    protected function getPackageProviders($app): array
    {
        return array_values(array_unique([...parent::getPackageProviders($app),
            PackmanServiceProvider::class, PluginsServiceProvider::class, PanelsServiceProvider::class,
            BladeIconsServiceProvider::class, BladeHeroiconsServiceProvider::class, LivewireServiceProvider::class,
            SupportServiceProvider::class, ActionsServiceProvider::class, FormsServiceProvider::class,
            InfolistsServiceProvider::class, NotificationsServiceProvider::class, SchemasServiceProvider::class,
            TablesServiceProvider::class, WidgetsServiceProvider::class, FilamentServiceProvider::class,
            PackmanFilamentServiceProvider::class, ProxymaFilamentServiceProvider::class, TestPanelProvider::class,
        ]));
    }

    protected function prepareFilament(): void
    {
        $this->app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));
        $user = new User;
        $user->forceFill(['id' => 1, 'name' => 'Fixture Operator', 'email' => 'operator@example.test']);
        $this->actingAs($user);
        $manager = $this->app->make('filament');
        $manager->setCurrentPanel($manager->getPanel('proxyma-test'));
        $manager->bootCurrentPanel();
        $this->commands();
    }

    protected function endpoint(): Endpoint
    {
        return $this->commands()->upsertEndpoint(new UpsertEndpointCommand(Uuid::random(), $this->tenant,
            ProxyProtocol::Http, 'proxy.example.test', 8080, null, $this->actor()));
    }
}
