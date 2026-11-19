<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Facade;
use Packman\PackmanServiceProvider;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Laravel\PluginsServiceProvider;
use Packman\Schema\Contracts\Migration\SchemaSets;
use Packman\Schema\Laravel\SchemaServiceProvider;
use Packman\Testkit\PackageTestCase;
use Proxyma\Configuration\ProxymaOptions;
use Proxyma\Contracts\Catalog\CatalogQueries;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Eloquent\EndpointRecord;
use Proxyma\Laravel\ProxymaServiceProvider;
use Proxyma\Plugins\ProxymaCorePlugin;

abstract class ProxymaHostRegistrationTestCase extends PackageTestCase
{
    protected string $prefix = 'first_';

    protected bool $disabled = false;

    protected function getPackageProviders($app): array
    {
        return [PackmanServiceProvider::class, PluginsServiceProvider::class, SchemaServiceProvider::class, ProxymaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('proxyma.table_prefix', $this->prefix);
        $app['config']->set('proxyma.connection', 'sqlite');
        if ($this->disabled) {
            $app['config']->set('packman.disabled_plugins', ['axiomasoft.proxyma']);
        }
    }
}

uses(ProxymaHostRegistrationTestCase::class);

it('uses the owning host for plugin metadata and SQL after another application boots', function (): void {
    $first = $this->app;
    $firstDb = $first['db']->connection();
    $firstDb->statement('create table first_endpoints (id integer)');
    $firstDb->statement('insert into first_endpoints values (19)');
    $firstPlugin = $first->make(ProxymaCorePlugin::class);
    $firstModel = new EndpointRecord;

    try {
        $this->prefix = 'second_';
        $second = $this->createApplication();
        $secondDb = $second['db']->connection();
        $secondDb->statement('create table second_endpoints (id integer)');
        $secondDb->statement('insert into second_endpoints values (71)');

        expect($second->make(PluginInspector::class)->facetsOf('axiomasoft.proxyma')->tablePrefix)->toBe('second_')
            ->and(PackageSchema::query($secondDb, 'endpoints')->value('id'))->toBe(71)
            ->and($firstPlugin->tablePrefix())->toBe('first_')
            ->and($firstModel->getTable())->toBe('first_endpoints')
            ->and(PackageSchema::query($firstDb, 'endpoints')->value('id'))->toBe(19);
    } finally {
        Container::setInstance($first);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($first);
    }
});

it('leaves disabled options, migrations, runtime and commands out of the second host', function (): void {
    $first = $this->app;
    $this->prefix = 'invalid prefix!';
    $this->disabled = true;

    try {
        $second = $this->createApplication();
        $second->register(ProxymaServiceProvider::class, true);
        expect($second->make(PluginInspector::class)->facetsOf('axiomasoft.proxyma'))->toBeNull()
            ->and($second->make(SchemaSets::class)->all())->toBe([])
            ->and($second->bound(ProxymaOptions::class))->toBeFalse()
            ->and($second->bound(CatalogQueries::class))->toBeFalse()
            ->and($second->make(Kernel::class)->all())->not->toHaveKey('proxyma:doctor')
            ->and($first->make(Kernel::class)->all())->toHaveKey('proxyma:doctor');
    } finally {
        Container::setInstance($first);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($first);
    }
});
