<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;
use Packman\Attributes\Api;
use Proxyma\Domain\Health\HealthEpochMap;

#[Api(since: '1.0')]
final readonly class LateReceiptClaim
{
    public string $receiptId;

    public function __construct(
        public Uuid $operationId,
        public Uuid $physicalAttemptId,
        public ContentHash $wireFingerprint,
        string $receiptId,
        public ContentHash $receiptDigest,
        public HealthEpochMap $authorizationHealthEpochs,
        public ?ResponseDescriptor $verifiedResponse = null,
    ) {
        if ($receiptId === '' || strlen($receiptId) > 255 || preg_match('/[\x00-\x20\x7f]/', $receiptId) === 1) {
            throw new InvalidArgumentException('Late receipt ID must be non-empty and free of whitespace or control bytes.');
        }

        $this->receiptId = $receiptId;
    }
}
