<?php

declare(strict_types=1);

namespace Proxyma\Domain\Routing;

use DateTimeImmutable;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Execution\Uuid;

final readonly class Candidate
{
    /**
     * @param  list<string>  $exclusions
     * @param  array<string, int>  $healthEpochs
     * @param  array<string, int>  $healthVersions
     * @param  array<string, int>  $budgetRevisions
     * @param  list<string>  $budgetScopes
     */
    public function __construct(
        public Uuid $routeId,
        public ?Route $route,
        public array $exclusions,
        public array $healthEpochs,
        public array $healthVersions,
        public array $budgetRevisions,
        public array $budgetScopes,
        public bool $budgetReady,
        public ?DateTimeImmutable $lastAuthorizedAt,
    ) {}

    public function eligible(): bool
    {
        return $this->route !== null && $this->exclusions === [];
    }
}
