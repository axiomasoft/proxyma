<?php

declare(strict_types=1);

namespace Proxyma\Domain\Routing;

use DateTimeImmutable;
use Packman\Attributes\Api;
use Proxyma\Domain\Catalog\RouteProfile;
use Proxyma\Domain\Catalog\Target;
use Proxyma\Domain\Policy\TenantKey;

#[Api(since: '1.0')]
final readonly class CandidateSnapshot
{
    /** @param list<Candidate> $candidates
     * @param  list<string>  $holds
     */
    public function __construct(
        public TenantKey $tenant,
        public ?RouteProfile $profile,
        public ?Target $target,
        public int $policyRevision,
        public DateTimeImmutable $observedAt,
        public array $candidates,
        public array $holds = [],
    ) {}
}
