<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class OperationCorrelation
{
    public DateTimeImmutable $deadlineAt;

    public ?string $externalOperationKey;

    public function __construct(
        public Uuid $operationId,
        public OwnerRef $owner,
        DateTimeImmutable $deadlineAt,
        ?string $externalOperationKey = null,
        public DemandPurpose $purpose = DemandPurpose::Ordinary,
        public ?Uuid $probeToken = null,
    ) {
        if ($externalOperationKey !== null && ($externalOperationKey === '' || strlen($externalOperationKey) > 255 || preg_match('/[\x00-\x20\x7f]/', $externalOperationKey) === 1)) {
            throw new InvalidArgumentException('External operation correlation key must be non-empty and free of whitespace or control bytes.');
        }

        if (($purpose === DemandPurpose::Probe) !== ($probeToken !== null)) {
            throw new InvalidArgumentException('Probe operation requires probeToken and non-probe operation forbids it.');
        }

        $this->deadlineAt = $deadlineAt->setTimezone(new DateTimeZone('UTC'));
        $this->externalOperationKey = $externalOperationKey;
    }
}
