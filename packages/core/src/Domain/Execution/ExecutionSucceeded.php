<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ExecutionSucceeded implements ExecutionResult
{
    /** @var non-empty-list<Uuid> */
    public array $attemptIds;

    public Uuid $finalAttemptId;

    /**
     * @param  list<Uuid>  $attemptIds
     */
    public function __construct(
        public ResponseDescriptor $response,
        array $attemptIds,
        public ?OutcomeClassification $outcome = null,
    ) {
        if ($attemptIds === []) {
            throw new InvalidArgumentException('ExecutionSucceeded requires at least one physical attempt UUID.');
        }

        $this->attemptIds = $attemptIds;
        $this->finalAttemptId = $attemptIds[array_key_last($attemptIds)];
    }
}
