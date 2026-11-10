<?php

declare(strict_types=1);

namespace Proxyma\Application\Extensions;

use Proxyma\Domain\Execution\ReadOnlyAttemptObservation;
use Proxyma\Domain\Panels\ExtensionKind;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class AttemptObservers
{
    public function __construct(private DefinitionRegistry $registry, private ConnectionScope $scope,
        private LoggerInterface $logger, private array $ids = []) {}

    public function notify(ReadOnlyAttemptObservation $observation): void
    {
        $this->scope->assertOutsideTransaction();
        foreach ($this->registry->selectExtensions(ExtensionKind::Observer, $this->ids) as $definition) {
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
