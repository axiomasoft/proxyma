<?php

declare(strict_types=1);

namespace Proxyma\Application\Maintenance;

use InvalidArgumentException;
use LogicException;
use Packman\Attributes\Api;
use Proxyma\Application\Admission\OperationCommands;
use Proxyma\Application\Admission\ReservationService;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Health\ProbeCoordinator;
use Proxyma\Application\Health\ProbeReview;
use Proxyma\Contracts\Admission\WorkBudget;
use Proxyma\Contracts\Catalog\CatalogQueries;
use Proxyma\Contracts\Execution\OutboundClient;
use Proxyma\Contracts\Policy\PolicyResolver;
use Proxyma\Domain\Execution\DemandPurpose;
use Proxyma\Domain\Execution\Duration;
use Proxyma\Domain\Execution\ExecutionContext;
use Proxyma\Domain\Execution\ExecutionResult;
use Proxyma\Domain\Execution\OperationCorrelation;
use Proxyma\Domain\Execution\OutboundRequest;
use Proxyma\Domain\Execution\OwnerRef;
use Proxyma\Domain\Execution\RequestTimeoutPolicy;
use Proxyma\Domain\Execution\RouteSelection;
use Proxyma\Domain\Execution\SafeUrl;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Database\DatabaseClock;

/** Один exact route/target, HEAD /, без retries/redirects; обычный admission обязателен. */
#[Api(since: '1.0')]
final readonly class BoundedProbe
{
    public function __construct(private ConnectionScope $scope, private CatalogAccess $access, private CatalogQueries $catalog,
        private PolicyResolver $policies, private OperationCommands $operations, private ReservationService $reservations,
        private ProbeCoordinator $probes, private OutboundClient $outbound) {}

    public function run(SubjectChain $subjects, Uuid $profileId, Uuid $routeId, Uuid $targetId, int $seconds = 30, ?ProbeReview $review = null): ExecutionResult
    {
        $this->scope->assertOutsideTransaction();
        if ($seconds < 16 || $seconds > 60) {
            throw new InvalidArgumentException('Probe duration must be 16..60 seconds.');
        }
        $tenant = $subjects->tenant;
        $this->access->authorize($tenant, 'health.probe.execute', $routeId);
        $profile = $this->catalog->findProfile($tenant, $profileId);
        $route = $this->catalog->findRoute($tenant, $routeId);
        $target = $this->catalog->findTarget($tenant, $targetId);
        if ($profile === null || $route === null || $target === null || ! $profile->containsRoute($routeId)) {
            throw new InvalidArgumentException('Probe requires an exact profile member and target.');
        }
        $policy = $this->policies->explain($subjects, $targetId)->effectivePolicy ?? throw new LogicException('Effective policy required.');
        if (! $policy->isProfileAllowed($profile->profileKey)
            || ($policy->routeProfileId !== null && ! $policy->routeProfileId->equals($profileId))
            || ($policy->routeProfileKey !== null && $policy->routeProfileKey !== $profile->profileKey)) {
            throw new InvalidArgumentException('Probe profile conflicts with policy.');
        }
        $id = Uuid::random();
        $now = DatabaseClock::now($this->scope->connection);
        $operation = new OperationCorrelation($id, new OwnerRef($tenant, 'standalone', $id->value, bin2hex(random_bytes(24))),
            $now->modify('+'.$seconds.' seconds'), purpose: DemandPurpose::Probe, probeToken: Uuid::random());
        $timeouts = new RequestTimeoutPolicy(min(1000, $policy->timeouts->connectTimeoutMs), min(1000, $policy->timeouts->readTimeoutMs),
            min(1000, $policy->timeouts->totalTimeoutMs));
        $this->operations->create($operation, $profileId, $targetId, $timeouts);
        $url = new SafeUrl($target->origin->value().'/');
        $selection = new RouteSelection($route->id, $route->kind, $profileId, $route->endpointId, $route->credentialVersion, $route->egress, $route->independenceGroup);
        $request = new WireRequest($id, Uuid::random(), $targetId, $policy->effectiveRevision, 'HEAD', $url, $timeouts, $operation->deadlineAt, purpose: DemandPurpose::Probe);
        $deadline = hrtime(true) + $seconds * 1_000_000_000;
        $work = new class($deadline) implements WorkBudget
        {
            public function __construct(private int $deadline) {}

            public function canStart(Duration $required): bool
            {
                return hrtime(true) + $required->nanos() < $this->deadline;
            }
        };
        $input = $this->reservations->prepare($request, $operation, $selection, $policy, $work);
        $this->probes->claim($input, $seconds, $review);

        return $this->outbound->execute(new OutboundRequest($id, $targetId, 'HEAD', $url, $timeouts, sendLimit: 1),
            new ExecutionContext($operation, $subjects, Duration::seconds($seconds), $deadline));
    }
}
