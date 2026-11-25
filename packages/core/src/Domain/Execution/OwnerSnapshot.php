<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class OwnerSnapshot
{
    public DateTimeImmutable $deadlineAt;

    /** @var array<string, int> */
    public array $controlVersions;

    /** @var array<string, int> */
    public array $subjectGenerations;

    /**
     * @param  array<string, int>  $controlVersions
     * @param  array<string, int>  $subjectGenerations
     */
    public function __construct(
        public OwnerRef $owner,
        DateTimeImmutable $deadlineAt,
        public int $inputRevision = 1,
        public ?ContentHash $inputHash = null,
        array $controlVersions = [],
        array $subjectGenerations = [],
        public ?Uuid $parentAttemptId = null,
        public ?Uuid $adapterHealthId = null,
        public ?int $adapterHealthEpoch = null,
        public DemandPurpose $purpose = DemandPurpose::Ordinary,
        public ?Uuid $probeToken = null,
    ) {
        if ($inputRevision < 1) {
            throw new InvalidArgumentException('Owner snapshot input revision must be at least 1.');
        }

        if (($adapterHealthId === null) !== ($adapterHealthEpoch === null) || ($adapterHealthEpoch !== null && $adapterHealthEpoch < 1)) {
            throw new InvalidArgumentException('Adapter health id and positive epoch must be provided together.');
        }

        if (($purpose === DemandPurpose::Probe) !== ($probeToken !== null)) {
            throw new InvalidArgumentException('Probe purpose requires a probe token and non-probe purpose forbids it.');
        }

        $normalizedControls = [];
        foreach ($controlVersions as $scopeKey => $version) {
            if ($scopeKey === '' || $version < 1) {
                throw new InvalidArgumentException('Control versions must map non-empty scope keys to positive integers.');
            }
            $normalizedControls[$scopeKey] = $version;
        }
        ksort($normalizedControls, SORT_STRING);

        $normalizedSubjects = [];
        foreach ($subjectGenerations as $subjectKey => $generation) {
            if ($subjectKey === '' || $generation < 1) {
                throw new InvalidArgumentException('Subject generations must map non-empty subject keys to positive integers.');
            }
            $normalizedSubjects[$subjectKey] = $generation;
        }
        ksort($normalizedSubjects, SORT_STRING);

        $this->deadlineAt = $deadlineAt->setTimezone(new DateTimeZone('UTC'));
        $this->controlVersions = $normalizedControls;
        $this->subjectGenerations = $normalizedSubjects;
    }
}
