<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Facade;
use Packman\Doctor\Contracts\DoctorRegistryContract;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Packman\PackmanServiceProvider;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Laravel\PluginsServiceProvider;
use Packman\Schema\Contracts\Migration\SchemaSets;
use Packman\Schema\Laravel\SchemaServiceProvider;
use Packman\Testkit\PackageTestCase;
use Proxyma\Application\Diagnostics\Doctor;
use Proxyma\Configuration\ProxymaOptions;
use Proxyma\Contracts\Catalog\CatalogQueries;
use Proxyma\Contracts\Health\DiagnosticsQueries;
use Proxyma\Diagnostics\ProxymaDoctorCheck;
use Proxyma\Domain\Execution\SchemaDiagnosticReport;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\HealthSnapshot;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\ConnectionScope;
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

it('enumerates diagnostics without storage and reports unavailable storage when diagnostics run', function (): void {
    $app = $this->app;
    $app['config']->set('database.connections', []);
    $resolutions = 0;
    $app->beforeResolving(DiagnosticsQueries::class, static function () use (&$resolutions): void {
        $resolutions++;
    });

    $registry = $app->make(DoctorRegistryContract::class);
    $checks = $registry->all();
    $registry->healthChecks();
    $check = $app->make(ProxymaDoctorCheck::class);
    $doctor = $app->make(Doctor::class);

    expect(array_map(static fn ($item): string => $item->key(), $checks))->toContain('proxyma.runtime.configuration')
        ->and($resolutions)->toBe(0)
        ->and($app->resolved(ConnectionScope::class))->toBeFalse()
        ->and($app['db']->getConnections())->toBe([]);

    $findings = $check->run(new DoctorContext);
    expect(array_map(static fn (DoctorFinding $finding): string => $finding->code, $findings))
        ->toContain('proxyma.doctor.schema_unreachable', 'proxyma.doctor.missing_connection')
        ->and($resolutions)->toBe(1);

    $report = $doctor->inspect();
    expect($report['healthy'])->toBeFalse()
        ->and($report['schema']->healthy)->toBeFalse()
        ->and($report['network_io'])->toBeFalse()
        ->and($resolutions)->toBe(2)
        ->and($app['db']->getConnections())->toBe([]);
});

it('keeps deferred diagnostic queries on their owning host after another host boots', function (): void {
    $first = $this->app;
    $firstCalls = 0;
    $secondCalls = 0;
    $queries = new class implements DiagnosticsQueries
    {
        public function verifySchemaCapabilities(): SchemaDiagnosticReport
        {
            return new SchemaDiagnosticReport(true, 'pgsql');
        }

        public function inspectRouteHealth(TenantKey $tenant, Uuid $routeId, ?Uuid $targetId = null): ?HealthSnapshot
        {
            return null;
        }

        public function inspectTargetHealth(TenantKey $tenant, Uuid $targetId): ?HealthSnapshot
        {
            return null;
        }
    };
    $first->bind(DiagnosticsQueries::class, static function () use (&$firstCalls, $queries): DiagnosticsQueries {
        $firstCalls++;

        return $queries;
    });
    $firstCheck = $first->make(ProxymaDoctorCheck::class);
    $firstDoctor = $first->make(Doctor::class);

    try {
        $this->prefix = 'second_';
        $second = $this->createApplication();
        $second->bind(DiagnosticsQueries::class, static function () use (&$secondCalls, $queries): DiagnosticsQueries {
            $secondCalls++;

            return $queries;
        });
        $secondCheck = $second->make(ProxymaDoctorCheck::class);

        expect($firstCalls)->toBe(0)->and($secondCalls)->toBe(0);
        $firstCheck->run(new DoctorContext);
        $secondCheck->run(new DoctorContext);
        $firstDoctor->inspect();

        expect($firstCalls)->toBe(2)
            ->and($secondCalls)->toBe(1)
            ->and($first['db']->getConnections())->toBe([])
            ->and($second['db']->getConnections())->toBe([]);
    } finally {
        Container::setInstance($first);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($first);
    }
});

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
