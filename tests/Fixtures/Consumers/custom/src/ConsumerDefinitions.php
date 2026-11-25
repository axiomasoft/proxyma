<?php

declare(strict_types=1);

namespace Consumer;

use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Contracts\Extensions\ExtensionProvider;
use Proxyma\Domain\Panels\PanelSlot;
use Proxyma\Domain\Panels\SubjectResolverDefinition;
use Proxyma\Domain\Policy\PanelDefinition;

final class ConsumerDefinitions implements ExtensionProvider
{
    public function register(DefinitionRegistry $registry): void
    {
        $registry->registerCapability('consumer.network');
        $registry->registerSubjectResolver(new SubjectResolverDefinition('consumer.subjects', new ConsumerSubjects, ['document']));
        $registry->registerPanel(new PanelDefinition('consumer.files', 'consumer.subjects', [new PanelSlot('read', ['timeout.connect_ms'])], ['consumer.network']));
    }
}
