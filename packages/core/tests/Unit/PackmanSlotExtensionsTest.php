<?php

declare(strict_types=1);

namespace Proxyma\Core\Tests\Unit;

use DateTimeImmutable;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Extensions\Concerns\Composition\ContributesToSlots;
use Packman\Extensions\Contracts\Composition\WithSlots;
use Packman\PackmanServiceProvider;
use Packman\Panels\Laravel\PanelsServiceProvider;
use Packman\Plugins\Contracts\Contributions\Extensions;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;
use Packman\Plugins\Laravel\PluginsServiceProvider;
use Packman\Schema\Laravel\SchemaServiceProvider;
use Packman\Testkit\PackageTestCase;
use Proxyma\Application\Execution\OutcomePolicyRegistry;
use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Application\Extensions\ExtensionConfiguration;
use Proxyma\Application\Policy\SettingRegistry;
use Proxyma\Application\Routing\StrategyRegistry;
use Proxyma\Contracts\Bindings\SubjectResolver;
use Proxyma\Contracts\Execution\OutcomePolicy;
use Proxyma\Contracts\Extensions\ExtensionProvider;
use Proxyma\Contracts\Routing\RouteSelectionStrategy;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Execution\AttemptResult;
use Proxyma\Domain\Execution\DecodedTextBody;
use Proxyma\Domain\Execution\OutcomeClassification;
use Proxyma\Domain\Execution\OutcomeDecision;
use Proxyma\Domain\Execution\RequestTimeoutPolicy;
use Proxyma\Domain\Execution\ResponseDescriptor;
use Proxyma\Domain\Execution\RouteSelectionContext;
use Proxyma\Domain\Execution\SafeUrl;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Health\HealthAttribution;
use Proxyma\Domain\Panels\DefinitionError;
use Proxyma\Domain\Panels\PanelSlot;
use Proxyma\Domain\Panels\SubjectResolverDefinition;
use Proxyma\Domain\Policy\PanelDefinition;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Laravel\ProxymaServiceProvider;
use Proxyma\Plugins\ProxymaCorePlugin;
use stdClass;

final class FixtureSlotStrategy implements RouteSelectionStrategy
{
    public function key(): string
    {
        return 'slot.strategy';
    }

    public function select(array $candidates, WireRequest $request, RouteSelectionContext $context): ?Route
    {
        return $candidates[0] ?? null;
    }
}

final class FixtureSlotOutcomePolicy implements OutcomePolicy
{
    public function key(): string
    {
        return 'slot.outcome';
    }

    public function classify(WireRequest $request, AttemptResult $attempt): OutcomeClassification
    {
        return new OutcomeClassification(OutcomeDecision::Accept, 'slot_ok', HealthAttribution::None);
    }
}

final class FixtureSlotSubjects implements SubjectResolver
{
    public function resolve(SubjectRef $leaf): SubjectChain
    {
        return new SubjectChain($leaf);
    }
}

final class FixtureSlotDefinitions implements ExtensionProvider
{
    public function register(DefinitionRegistry $registry): void
    {
        $registry->registerCapability('slot.capability');
        $registry->registerSubjectResolver(new SubjectResolverDefinition('slot.subjects', new FixtureSlotSubjects, ['document']));
        $registry->registerPanel(new PanelDefinition('slot.files', 'slot.subjects', [new PanelSlot('read', ['timeout.connect_ms'])], ['slot.capability']));
    }
}

final class FixtureSlotConsumerPlugin implements ExtensionPlugin, WithSlots
{
    use ContributesToSlots;

    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'fixture.slot-consumer',
            version: '1.0.0',
            requires: ['axiomasoft.proxyma'],
        );
    }

    public function register(PluginRegistrar $registrar): void {}

    /** @return list<array{slot: class-string, id: string, implementation: class-string, priority?: int, key?: string|null}> */
    public function slotContributions(): array
    {
        return [
            ['slot' => RouteSelectionStrategy::class, 'id' => 'slot.strategy', 'implementation' => FixtureSlotStrategy::class, 'key' => 'slot.strategy'],
            ['slot' => OutcomePolicy::class, 'id' => 'slot.outcome', 'implementation' => FixtureSlotOutcomePolicy::class, 'key' => 'slot.outcome'],
            ['slot' => ExtensionProvider::class, 'id' => 'slot.definitions', 'implementation' => FixtureSlotDefinitions::class],
        ];
    }
}

final class FixtureUnscopedSlotPlugin implements ExtensionPlugin, WithSlots
{
    use ContributesToSlots;

    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'fixture.unscoped-consumer',
            version: '1.0.0',
            requires: [],
        );
    }

    public function register(PluginRegistrar $registrar): void {}

    /** @return list<array{slot: class-string, id: string, implementation: class-string, priority?: int, key?: string|null}> */
    public function slotContributions(): array
    {
        return [
            ['slot' => RouteSelectionStrategy::class, 'id' => 'unscoped.strategy', 'implementation' => FixtureSlotStrategy::class, 'key' => 'unscoped.strategy'],
        ];
    }
}

abstract class PackmanSlotExtensionsTestCase extends PackageTestCase
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            PackmanServiceProvider::class,
            SchemaServiceProvider::class,
            PluginsServiceProvider::class,
            PanelsServiceProvider::class,
            ProxymaServiceProvider::class,
        ];
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('packman.plugins', [FixtureSlotConsumerPlugin::class]);
        $app['config']->set('proxyma.extensions', [
            'route_strategies' => ['slot.strategy'],
            'outcome_policies' => ['slot.outcome'],
        ]);
    }
}

uses(PackmanSlotExtensionsTestCase::class);

it('wires consumer ExtensionPlugin WithSlots contributions through Packman Extensions without parallel instantiation', function (): void {
    /** @var Extensions $extensions */
    $extensions = $this->app->make(Extensions::class);
    $registry = $this->app->make(DefinitionRegistry::class);

    expect($extensions->handlerKeys(RouteSelectionStrategy::class))->toContain('slot.strategy')
        ->and($extensions->handlerKeys(OutcomePolicy::class))->toContain('slot.outcome')
        ->and($registry->hasPanel('slot.files'))->toBeTrue()
        ->and($this->app->make(StrategyRegistry::class)->get('slot.strategy'))->toBeInstanceOf(FixtureSlotStrategy::class)
        ->and($this->app->make(SettingRegistry::class)->descriptor('route.strategy')->allowedValues)->toContain('slot.strategy');

    $wire = new WireRequest(
        Uuid::random(),
        Uuid::random(),
        Uuid::random(),
        1,
        'GET',
        new SafeUrl('https://example.test/'),
        new RequestTimeoutPolicy(100, 100, 100, 0),
        new DateTimeImmutable('+1 minute'),
    );
    $attempt = AttemptResult::delivered(new ResponseDescriptor(200, [], new DecodedTextBody('ok'), 2, 2));

    expect($this->app->make(OutcomePolicyRegistry::class)->resolve('slot.outcome')->classify($wire, $attempt)->businessMeaning)->toBe('slot_ok');
});

it('rejects non-ExtensionPlugin classes and Packman plugins lacking axiomasoft.proxyma ancestor dependency', function (): void {
    $registry = new DefinitionRegistry($this->app->make(SettingRegistry::class));
    $configuration = new ExtensionConfiguration($this->app);

    expect(fn () => $configuration->register($registry, [
        'approved_providers' => ['bad' => stdClass::class],
        'providers' => ['bad'],
    ]))->toThrow(DefinitionError::class);

    $isolated = new Application(dirname(__DIR__, 4));
    $cfg = new ConfigRepository([
        'packman' => [
            'plugins' => [ProxymaCorePlugin::class, FixtureUnscopedSlotPlugin::class],
        ],
    ]);
    $isolated->instance('config', $cfg);
    $isolated->instance(Repository::class, $cfg);
    new PluginsServiceProvider($isolated)->register();

    expect(fn () => $isolated->make(Extensions::class))->toThrow(InvalidConfiguration::class);
});
