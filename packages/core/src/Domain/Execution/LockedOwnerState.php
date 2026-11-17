<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class LockedOwnerState
{
    public DateTimeImmutable $deadlineAt;

    /** @var array<string, int> */
    public array $pinnedVersions;

    /**
     * @param  array<string, int>  $pinnedVersions
     */
    public function __construct(
        public OwnerRef $owner,
        public DemandPurpose $purpose,
        DateTimeImmutable $deadlineAt,
        array $pinnedVersions = [],
        public ?Uuid $probeToken = null,
        public ?Uuid $adapterHealthId = null,
        public ?int $adapterHealthEpoch = null,
    ) {
        if (($purpose === DemandPurpose::Probe) !== ($probeToken !== null)) {
            throw new InvalidArgumentException('Locked owner state requires probeToken iff purpose is Probe.');
        }

        if (($adapterHealthId === null) !== ($adapterHealthEpoch === null) || ($adapterHealthEpoch !== null && $adapterHealthEpoch < 1)) {
            throw new InvalidArgumentException('Locked owner adapter health id and positive epoch must be paired.');
        }

        $normalized = [];
        foreach ($pinnedVersions as $key => $version) {
            if ($key === '' || $version < 1) {
                throw new InvalidArgumentException('Pinned owner versions must map non-empty keys to positive integers.');
            }
            $normalized[$key] = $version;
        }
        ksort($normalized, SORT_STRING);

        $this->deadlineAt = $deadlineAt->setTimezone(new DateTimeZone('UTC'));
        $this->pinnedVersions = $normalized;
    }
}
