<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Enums\CheckStatus;
use Packman\PackmanServiceProvider;
use Packman\Schema\Contracts\Migration\SchemaSets;
use Packman\Schema\Enums\SchemaLifecycle;
use Packman\Schema\Enums\SchemaScope;
use Proxyma\Contracts\Health\DiagnosticsQueries;
use Proxyma\Diagnostics\ProxymaDoctorCheck;
use Proxyma\Tests\Support\CatalogTestCase;

require_once __DIR__.'/../Support/CatalogTestCase.php';

abstract class PackmanSchemaDoctorPgTestCase extends CatalogTestCase
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return array_merge([PackmanServiceProvider::class], parent::getPackageProviders($app));
    }
}

uses(PackmanSchemaDoctorPgTestCase::class)->group('pg');

it('registers proxyma central pgsql schema set and reapplies migrations idempotently', function (): void {
    $registry = $this->app->make(SchemaSets::class);
    $set = $registry->get('axiomasoft/proxyma:proxyma');

    expect($set->package)->toBe('axiomasoft/proxyma')
        ->and($set->name)->toBe('proxyma')
        ->and($set->scope)->toBe(SchemaScope::Central)
        ->and($set->lifecycle)->toBe(SchemaLifecycle::Platform)
        ->and($set->drivers)->toBe(['pgsql']);

    $this->migrateSchemaSets(['axiomasoft/proxyma:proxyma'], 'pgsql');

    $capabilities = $this->app->make(DiagnosticsQueries::class)->verifySchemaCapabilities();

    expect($capabilities->healthy)->toBeTrue()
        ->and($capabilities->missingTables)->toBe([])
        ->and($capabilities->missingConstraints)->toBe([]);
});

it('reports weak wire digest key and healthy status through ProxymaDoctorCheck and packman:doctor', function (): void {
    $this->withoutMockingConsoleOutput();

    $weakCode = Artisan::call('packman:doctor', ['--json' => true, '--prefix' => ['proxyma']]);
    /** @var array<string, mixed> $weakReport */
    $weakReport = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($weakCode)->toBe(1)
        ->and($weakReport['schema'])->toBe('packman.doctor/v1')
        ->and($weakReport['ok'])->toBeFalse()
        ->and($weakReport['summary']['error'])->toBeGreaterThanOrEqual(1);

    $this->reconfigureProxyma(['wire.digest_key' => str_repeat('a', 32)]);

    $findings = $this->app->make(ProxymaDoctorCheck::class)->run(new DoctorContext);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->code)->toBe('proxyma.doctor.healthy')
        ->and($findings[0]->status)->toBe(CheckStatus::Ok);

    $healthyCode = Artisan::call('packman:doctor', ['--json' => true, '--prefix' => ['proxyma']]);
    /** @var array<string, mixed> $healthyReport */
    $healthyReport = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($healthyCode)->toBe(0)
        ->and($healthyReport['schema'])->toBe('packman.doctor/v1')
        ->and($healthyReport['ok'])->toBeTrue()
        ->and($healthyReport['summary']['error'])->toBe(0)
        ->and($healthyReport['checks'][0]['key'])->toBe('proxyma.runtime.configuration')
        ->and($healthyReport['checks'][0]['findings'][0]['code'])->toBe('proxyma.doctor.healthy');
});
