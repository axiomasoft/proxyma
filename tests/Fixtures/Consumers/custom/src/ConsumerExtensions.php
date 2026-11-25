<?php

declare(strict_types=1);

namespace Consumer;

use Packman\Extensions\Concerns\Composition\ContributesToSlots;
use Packman\Extensions\Contracts\Composition\WithSlots;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;
use Proxyma\Contracts\Bindings\SubjectResolver;
use Proxyma\Contracts\Execution\OutcomePolicy;
use Proxyma\Contracts\Extensions\ExtensionProvider;
use Proxyma\Contracts\Routing\RouteSelectionStrategy;

final class ConsumerExtensions implements ExtensionPlugin, WithSlots
{
    use ContributesToSlots;

    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'consumer.proxyma',
            version: '1.0.0',
            requires: ['axiomasoft.proxyma'],
        );
    }

    public function register(PluginRegistrar $registrar): void {}

    /** @return list<array{slot: class-string, id: string, implementation: class-string, priority?: int, key?: string|null}> */
    public function slotContributions(): array
    {
        return [
            ['slot' => RouteSelectionStrategy::class, 'id' => 'consumer.first', 'implementation' => FirstRoute::class, 'key' => 'consumer.first'],
            ['slot' => OutcomePolicy::class, 'id' => 'consumer.accept', 'implementation' => AcceptResponse::class, 'key' => 'consumer.accept'],
            ['slot' => SubjectResolver::class, 'id' => 'consumer.subjects', 'implementation' => ConsumerSubjects::class, 'key' => 'consumer.subjects'],
            ['slot' => ExtensionProvider::class, 'id' => 'consumer.definitions', 'implementation' => ConsumerDefinitions::class],
        ];
    }
}
