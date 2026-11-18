<?php

declare(strict_types=1);

use Filament\Panel;
use Opis\JsonSchema\Validator;
use Packman\Config\Data\SecretRef;
use Packman\Config\Data\TablePrefix;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Context\Data\ScopeRef;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Packman\Doctor\Laravel\DoctorChecks;
use Packman\Errors\Contracts\DescribesError;
use Packman\Errors\Contracts\ProblemRegistry;
use Packman\Errors\Exceptions\CodedException;
use Packman\Extensions\Contracts\Composition\WithCapabilities;
use Packman\Extensions\Contracts\Composition\WithPanels;
use Packman\Extensions\Contracts\Composition\WithSlots;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Extensions\Contracts\Runtime\WithDiagnostics;
use Packman\Extensions\ExtensionInspector;
use Packman\Filament\Contracts\FilamentPanelPlugin;
use Packman\Filament\Forms\BindingEditor as PackmanBindingEditor;
use Packman\PackmanServiceProvider;
use Packman\Panels\Contracts\Registry\PanelProvider;
use Packman\Panels\Data\Panel\PanelContext;
use Packman\Panels\Data\Panel\PanelReference;
use Packman\Panels\Laravel\PanelsServiceProvider;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Laravel\PluginsServiceProvider;
use Packman\Schema\Contracts\Migration\SchemaSetProvider;
use Packman\Schema\Enums\SchemaScope;
use Packman\Schema\Laravel\SchemaServiceProvider;
use Packman\Testkit\PackageTestCase;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Catalog\CatalogConflict;
use Proxyma\Application\Catalog\CatalogInvalidMutation;
use Proxyma\Application\Catalog\CatalogNotFound;
use Proxyma\Application\Catalog\CatalogStorageFailure;
use Proxyma\Application\Diagnostics\Doctor;
use Proxyma\Configuration\ProxymaOptions;
use Proxyma\Configuration\TransactionsOptions;
use Proxyma\Contracts\Authorization\Authorizer;
use Proxyma\Contracts\Health\DiagnosticsQueries;
use Proxyma\Database\ProxymaSchemaSets;
use Proxyma\Diagnostics\ProxymaDoctorCheck;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Admission\DemandWait;
use Proxyma\Domain\Admission\NeedsReplan;
use Proxyma\Domain\Admission\ReservationDeferred;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Execution\RetryAt;
use Proxyma\Domain\Execution\SchemaDiagnosticReport;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\HealthSnapshot;
use Proxyma\Domain\Panels\DefinitionError;
use Proxyma\Domain\Panels\PanelFormData;
use Proxyma\Domain\Panels\PanelSlot;
use Proxyma\Domain\Panels\SubjectResolutionFailed;
use Proxyma\Domain\Policy\InvalidSettingPatch;
use Proxyma\Domain\Policy\PanelDefinition;
use Proxyma\Domain\Policy\PolicyConflict;
use Proxyma\Domain\Policy\PolicyStorageFailure;
use Proxyma\Domain\Policy\SettingChange;
use Proxyma\Domain\Policy\SettingPatch;
use Proxyma\Domain\Policy\SubjectPolicyBinding;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Errors\ProxymaErrorCode;
use Proxyma\Filament\BindingEditor;
use Proxyma\Filament\ProxymaFilamentServiceProvider;
use Proxyma\Filament\ProxymaPlugin;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Eloquent\EndpointRecord;
use Proxyma\Infrastructure\Secrets\SecretStorageFailure;
use Proxyma\Laravel\ProxymaServiceProvider;
use Proxyma\Panels\ProxymaPanelProvider;
use Proxyma\Plugins\ProxymaCorePlugin;

abstract class PackmanFoundationTestCase extends PackageTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            PackmanServiceProvider::class,
            SchemaServiceProvider::class,
            PluginsServiceProvider::class,
            PanelsServiceProvider::class,
            ProxymaServiceProvider::class,
            ProxymaFilamentServiceProvider::class,
        ];
    }
}

uses(PackmanFoundationTestCase::class);

it('compiles default ProxymaOptions and enforces closed schema with SecretRef masking', function (): void {
    $raw = require dirname(__DIR__, 2).'/config/proxyma.php';
    $raw['wire']['digest_key'] = 'super-secret-wire-digest-key-at-least-32-bytes';

    $options = ProxymaOptions::fromArray($raw, 'proxyma');

    expect($options->connection)->toBeString()->not->toBe('')
        ->and($options->tablePrefix)->toBeInstanceOf(TablePrefix::class)
        ->and($options->tablePrefix->value())->toBe('pxm_')
        ->and($options->wireDigestKey)->toBeInstanceOf(SecretRef::class)
        ->and($options->wireDigestKey->reveal())->toBe('super-secret-wire-digest-key-at-least-32-bytes')
        ->and(json_encode($options, JSON_THROW_ON_ERROR))->not->toContain('super-secret-wire-digest-key')
        ->and(print_r($options, true))->not->toContain('super-secret-wire-digest-key');

    $typo = $raw;
    $typo['conection'] = 'pgsql';

    expect(fn (): ProxymaOptions => ProxymaOptions::fromArray($typo, 'proxyma'))
        ->toThrow(InvalidConfiguration::class, 'connection');

    $unsafe = $raw;
    $unsafe['safety']['admission'] = 'optional';

    expect(fn (): ProxymaOptions => ProxymaOptions::fromArray($unsafe, 'proxyma'))
        ->toThrow(InvalidConfiguration::class, 'mandatory');

    foreach (['2s; DROP TABLE x', '500 ms', '-1ms', '01s', ''] as $badDuration) {
        $badTx = $raw;
        $badTx['transactions'] = ['lock_timeout' => $badDuration];
        expect(fn (): ProxymaOptions => ProxymaOptions::fromArray($badTx, 'proxyma'))
            ->toThrow(InvalidConfiguration::class, 'lock_timeout');
        expect(fn (): TransactionsOptions => new TransactionsOptions(lockTimeout: $badDuration))
            ->toThrow(InvalidArgumentException::class);

        $badStmt = $raw;
        $badStmt['transactions'] = ['statement_timeout' => $badDuration];
        expect(fn (): ProxymaOptions => ProxymaOptions::fromArray($badStmt, 'proxyma'))
            ->toThrow(InvalidConfiguration::class, 'statement_timeout');
    }

    foreach ([0, 21] as $badAttempts) {
        $badTx = $raw;
        $badTx['transactions'] = ['max_attempts' => $badAttempts];
        expect(fn (): ProxymaOptions => ProxymaOptions::fromArray($badTx, 'proxyma'))
            ->toThrow(InvalidConfiguration::class, 'max_attempts');
        expect(fn (): TransactionsOptions => new TransactionsOptions(maxAttempts: $badAttempts))
            ->toThrow(InvalidArgumentException::class);
    }

    expect($options->policy->panelKey)->toBeNull();
    $withPanelKey = $raw;
    $withPanelKey['policy']['panel_key'] = 'files';
    expect(ProxymaOptions::fromArray($withPanelKey, 'proxyma')->policy->panelKey)->toBe('files');

    $schema = json_decode(file_get_contents(dirname(__DIR__, 2).'/resources/schemas/config.schema.json') ?: '', false, 512, JSON_THROW_ON_ERROR);
    $validator = new Validator;

    $validPayload = json_decode(json_encode(['proxyma' => ['policy' => ['panel_key' => 'files']]], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    expect($validator->validate($validPayload, $schema)->isValid())->toBeTrue();

    $nullPayload = json_decode(json_encode(['proxyma' => ['policy' => ['panel_key' => null]]], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    expect($validator->validate($nullPayload, $schema)->isValid())->toBeTrue();

    $unknownPolicyKey = json_decode(json_encode(['proxyma' => ['policy' => ['unknown_key' => 'files']]], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    expect($validator->validate($unknownPolicyKey, $schema)->isValid())->toBeFalse();
});

it('registers all ProxymaErrorCode entries and DescribesError exceptions in ProblemRegistry', function (): void {
    /** @var ProblemRegistry $registry */
    $registry = $this->app->make(ProblemRegistry::class);

    $this->assertAllErrorCodesRegistered($registry, ProxymaErrorCode::class);

    $coded = [
        [new AuthorizationDenied, 403],
        [new CatalogConflict, 409],
        [new CatalogInvalidMutation, 422],
        [new CatalogNotFound, 404],
        [new CatalogStorageFailure, 500],
        [new AdmissionRejected('capacity_wait'), 409],
        [new ReservationDeferred(new DemandWait('rate_wait', new RetryAt(new DateTimeImmutable('+5 seconds')))), 429],
        [new NeedsReplan(TenantKey::system(), Uuid::random(), Uuid::random()), 409],
        [new PolicyConflict, 409],
        [new PolicyStorageFailure, 500],
        [new SubjectResolutionFailed, 404],
        [new SecretStorageFailure, 500],
    ];

    foreach ($coded as [$exception, $status]) {
        expect($exception)->toBeInstanceOf(CodedException::class);
        $this->assertCodedExceptionRegistered($registry, $exception, $status);
    }

    foreach ([new InvalidSettingPatch, new DefinitionError('invalid')] as $invalidArg) {
        expect($invalidArg)->toBeInstanceOf(DescribesError::class);
        $registry->assertOccurrence($invalidArg->errorDescriptor(), 422);
    }
});

it('declares stateless ProxymaCorePlugin facets, schema set, panels, and thin providers', function (): void {
    $plugin = new ProxymaCorePlugin;
    $profile = ExtensionInspector::inspect($plugin);
    $interfaces = array_values(class_implements($plugin));
    sort($interfaces);

    $expectedInterfaces = [
        WithCapabilities::class,
        WithPanels::class,
        WithSlots::class,
        WithConfiguration::class,
        WithSchema::class,
        WithDiagnostics::class,
        ExtensionPlugin::class,
    ];
    sort($expectedInterfaces);

    expect($interfaces)->toBe($expectedInterfaces)
        ->and($profile->hasConfiguration)->toBeTrue()
        ->and($profile->hasSchema)->toBeTrue()
        ->and($profile->tablePrefix)->toBe('pxm_')
        ->and($profile->tableNouns)->toHaveCount(32)
        ->and($profile->schemaSetProviders)->toBe([ProxymaSchemaSets::class])
        ->and($profile->panelProviders)->toBe([ProxymaPanelProvider::class])
        ->and($profile->healthChecks)->toBe([ProxymaDoctorCheck::class]);

    /** @var SchemaSetProvider $schemaSets */
    $schemaSets = $this->app->make(ProxymaSchemaSets::class);
    $sets = $schemaSets->sets();
    expect($sets)->toHaveCount(1)
        ->and($sets[0]->id())->toBe('axiomasoft/proxyma-core:proxyma')
        ->and($sets[0]->scope)->toBe(SchemaScope::Central)
        ->and($sets[0]->drivers)->toBe(['pgsql']);

    $coreProviderLines = count(file(dirname(__DIR__, 2).'/src/Laravel/ProxymaServiceProvider.php') ?: []);
    $filamentProviderLines = count(file(dirname(__DIR__, 3).'/filament/src/ProxymaFilamentServiceProvider.php') ?: []);
    expect($coreProviderLines)->toBeLessThanOrEqual(80)
        ->and($filamentProviderLines)->toBeLessThanOrEqual(80);
});

it('bridges Proxyma panel definitions, slots, form projections, and Filament panel plugin', function (): void {
    $slot = new PanelSlot('read', ['timeout.connect_ms'], new SettingPatch(1, ['timeout.connect_ms' => SettingChange::set(250)]), false);
    $packmanSlot = $slot->toPackmanSlot();
    $roundTripSlot = PanelSlot::fromPackmanSlot($packmanSlot);
    expect($roundTripSlot->id)->toBe('read')
        ->and($roundTripSlot->settings)->toBe(['timeout.connect_ms'])
        ->and($roundTripSlot->defaults->changes['timeout.connect_ms']->value)->toBe(250)
        ->and($roundTripSlot->inheritDefaults)->toBeFalse();

    $definition = new PanelDefinition('files', 'tenant_subject', [$slot], ['cap.v1']);
    $descriptor = $definition->toPackmanDescriptor();
    expect($descriptor->version)->toBe('panel/1')
        ->and($descriptor->key)->toBe('files')
        ->and($descriptor->requiredCapability)->toBe('cap.v1');

    $binding = new SubjectPolicyBinding(Uuid::random(), new SubjectRef(TenantKey::system(), 'folder', '12'), null, 'default', 2, 'files', 'read');
    $form = new PanelFormData('files', 'read', $binding, [], null, 'v2');
    $packmanForm = $form->toPackmanFormData();
    $roundTripForm = PanelFormData::fromPackmanFormData($packmanForm, $binding);
    expect($packmanForm->binding['binding_id'])->toBe($binding->bindingId->value)
        ->and($roundTripForm->editVersion)->toBe('v2');

    /** @var PanelProvider $provider */
    $provider = $this->app->make(ProxymaPanelProvider::class);
    $denyGuest = $provider->access(new PanelContext(new ScopeRef('acme'), 'guest', null), new PanelReference('proxyma', 'default', null));
    expect($denyGuest->view)->toBeFalse()->and($denyGuest->reason)->toBe('unauthenticated_actor');

    $custom = new ProxymaPanelProvider(new class implements Authorizer
    {
        public function authorize(ActorRef $actor, TenantKey $tenant, string $ability, Uuid $resourceId): void
        {
            if ($ability === 'binding.execute') {
                throw new AuthorizationDenied;
            }
        }
    });
    $access = $custom->access(new PanelContext(new ScopeRef('acme'), 'user', '42'), new PanelReference('proxyma', 'files', null));
    expect($access->view)->toBeTrue()->and($access->actions)->toBe(['view', 'update']);

    $plugin = ProxymaPlugin::make()->guardPanel('admin')->enforce()->abilities(['view', 'update'])->exclude(['pages' => ['AuditPage']]);
    expect($plugin)->toBeInstanceOf(FilamentPanelPlugin::class)
        ->and($plugin->getId())->toBe('proxyma')
        ->and($plugin->getGuardPanel())->toBe('admin')
        ->and($plugin->isEnforced())->toBeTrue()
        ->and($plugin->getAbilities())->toBe(['view', 'update'])
        ->and(BindingEditor::fieldId('timeout.connect_ms'))->toBe(PackmanBindingEditor::fieldId('timeout.connect_ms'));

    $adminPanel = Panel::make()->id('admin')->path('admin')->plugin(ProxymaPlugin::make()->guardPanel('admin')->enforce());
    $operatorPanel = Panel::make()->id('operator')->path('operator')->plugin(ProxymaPlugin::make()->guardPanel('operator')->enforce(false));
    expect(ProxymaPlugin::current($adminPanel))->not->toBe(ProxymaPlugin::current($operatorPanel))
        ->and(ProxymaPlugin::current($adminPanel)->getGuardPanel())->toBe('admin')
        ->and(ProxymaPlugin::current($adminPanel)->isEnforced())->toBeTrue()
        ->and(ProxymaPlugin::current($operatorPanel)->getGuardPanel())->toBe('operator')
        ->and(ProxymaPlugin::current($operatorPanel)->isEnforced())->toBeFalse();
});

it('registers ProxymaDoctorCheck under packman doctor tags and flags weak wire digest key', function (): void {
    $checks = $this->app->tagged(DoctorChecks::TAG);
    $found = false;

    foreach ($checks as $check) {
        if ($check instanceof ProxymaDoctorCheck) {
            $found = true;
            $findings = $check->run(new DoctorContext);
            $codes = array_map(static fn (DoctorFinding $f): string => $f->code, $findings);
            expect($codes)->toContain('proxyma.doctor.weak_wire_digest_key');
        }
    }

    expect($found)->toBeTrue();
});

it('masks raw exception messages and delegates Doctor inspection through ProxymaDoctorCheck', function (): void {
    $secret = 'P@ssw0rdSecret123';
    $failingQueries = new class($secret) implements DiagnosticsQueries
    {
        public function __construct(private readonly string $secret) {}

        public function verifySchemaCapabilities(): SchemaDiagnosticReport
        {
            throw new RuntimeException('PG connection error postgres://proxyma:'.$this->secret.'@127.0.0.1:5432/db');
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

    $check = new ProxymaDoctorCheck(
        config: null,
        queries: $failingQueries,
        optionsResolver: static function () use ($secret): ProxymaOptions {
            throw new RuntimeException('Invalid key '.$secret.' in configuration');
        },
    );

    $findings = $check->run(new DoctorContext);
    $codes = array_map(static fn (DoctorFinding $f): string => $f->code, $findings);

    expect($codes)->toContain('proxyma.doctor.schema_unreachable', 'proxyma.doctor.invalid_configuration')
        ->and(json_encode($findings, JSON_THROW_ON_ERROR))->not->toContain($secret);

    $doctor = new Doctor($failingQueries, check: $check);
    $report = $doctor->inspect();

    expect($report['healthy'])->toBeFalse()
        ->and($report['configuration_valid'])->toBeFalse()
        ->and($report['schema']->healthy)->toBeFalse()
        ->and($report['network_io'])->toBeFalse()
        ->and(json_encode($report, JSON_THROW_ON_ERROR))->not->toContain($secret);

    $healthyQueries = new class implements DiagnosticsQueries
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

    $healthyDoctor = new Doctor($healthyQueries, new ProxymaOptions(wireDigestKey: new SecretRef(str_repeat('b', 32))));
    $healthyReport = $healthyDoctor->inspect();
    expect($healthyReport['healthy'])->toBeTrue()
        ->and($healthyReport['configuration_valid'])->toBeTrue();

    $weakDoctor = new Doctor($healthyQueries, new ProxymaOptions(wireDigestKey: new SecretRef('weak')));
    expect($weakDoctor->inspect()['healthy'])->toBeFalse()
        ->and($weakDoctor->inspect()['configuration_valid'])->toBeFalse();
});

it('honors configured proxyma.table_prefix dynamically across PackageSchema, Eloquent models, and ProxymaCorePlugin', function (): void {
    expect(PackageSchema::prefix())->toBe('pxm_')
        ->and((new EndpointRecord)->getTable())->toBe('pxm_endpoints');

    $this->app['config']->set('proxyma.table_prefix', 'audit_');
    $this->app->forgetInstance(ProxymaOptions::class);

    $plugin = new ProxymaCorePlugin;
    $profile = ExtensionInspector::inspect($plugin);

    expect(PackageSchema::prefix())->toBe('audit_')
        ->and((new EndpointRecord)->getTable())->toBe('audit_endpoints')
        ->and(PackageSchema::tables()['attempts'])->toBe('audit_attempts')
        ->and($plugin->tablePrefix())->toBe('audit_')
        ->and($profile->tablePrefix)->toBe('audit_');

    $this->app->instance(ProxymaOptions::class, new ProxymaOptions(tablePrefix: new TablePrefix('bound_')));
    expect(PackageSchema::prefix())->toBe('bound_')
        ->and((new EndpointRecord)->getTable())->toBe('bound_endpoints')
        ->and($plugin->tablePrefix())->toBe('bound_');

    $this->app->forgetInstance(ProxymaOptions::class);
    $this->app['config']->set('proxyma.maintenance.batch_size', 65);
    expect(fn () => $this->app->make(ProxymaOptions::class))->toThrow(InvalidConfiguration::class);
    expect(PackageSchema::tables()['operations'])->toBe('audit_operations');
});
