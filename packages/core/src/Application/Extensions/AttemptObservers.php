<?php

declare(strict_types=1);

namespace Proxyma\Application\Extensions;

use Packman\Attributes\Api;
use Proxyma\Contracts\Execution\OutboundObserver;
use Proxyma\Domain\Execution\ReadOnlyAttemptObservation;
use Proxyma\Domain\Panels\ExtensionKind;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Psr\Log\LoggerInterface;
use Throwable;

#[Api(since: '1.0')]
final readonly class AttemptObservers
{
    /** @param list<string> $ids */
    public function __construct(private DefinitionRegistry $registry, private ConnectionScope $scope,
        private LoggerInterface $logger, private array $ids = []) {}

    public function notify(ReadOnlyAttemptObservation $observation): void
    {
        $this->scope->assertOutsideTransaction();
        foreach ($this->registry->selectExtensions(ExtensionKind::Observer, $this->ids) as $definition) {
            if (! $definition->implementation instanceof OutboundObserver) {
                continue;
            }
            try {
                $definition->implementation->onAttemptCompleted($observation);
            } catch (Throwable) {
                // Ошибка listener не меняет сохранённый факт и не выходит в retry loop.
                try {
                    $this->logger->error('proxyma.observer_failed', ['observer' => $definition->id,
                        'physical_attempt_id' => $observation->physicalAttemptId->value]);
                } catch (Throwable) {
                    // Даже отказ logging backend не должен превращать delivery в retry.
                }
            }
        }
    }
}
