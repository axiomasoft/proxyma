<?php

declare(strict_types=1);

namespace Proxyma\Application\Routing;

use InvalidArgumentException;
use Packman\Attributes\Api;
use Proxyma\Application\Extensions\ExtensionRuntime;
use Proxyma\Contracts\Catalog\CandidateSnapshots;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Execution\ResolvedUrl;
use Proxyma\Domain\Execution\RouteSelection;
use Proxyma\Domain\Execution\RouteSelectionContext;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Policy\EffectivePolicy;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Domain\Routing\Candidate;
use Proxyma\Domain\Routing\SelectionResult;

#[Api(since: '1.0')]
final readonly class RouteSelector
{
    /** @param list<string> $filterIds */
    public function __construct(private CandidateSnapshots $snapshots, private StrategyRegistry $strategies,
        private ?ExtensionRuntime $extensions = null, private array $filterIds = []) {}

    public function select(TenantKey $tenant, Uuid $profileId, EffectivePolicy $policy, WireRequest $request, RouteSelectionContext $context): SelectionResult
    {
        if (! $tenant->equals($policy->tenantKey) || ! $request->targetId->equals($policy->targetId)
            || $request->policyRevision !== $policy->effectiveRevision) {
            throw new InvalidArgumentException('Request, policy and tenant do not describe the same authority.');
        }
        $snapshot = $this->snapshots->load($tenant, $profileId, $request->targetId, $policy);
        if ($snapshot->holds !== [] || $snapshot->profile === null || $snapshot->target === null) {
            return new SelectionResult($snapshot, null, null, 'configuration_hold');
        }
        $originAllowed = $request->url->origin->equals($snapshot->target->origin)
            || ResolvedUrl::isUrlVerifiedForTarget($snapshot->target->id, $request->url, $snapshot->target->revision, $snapshot->target->origin);
        if (! $originAllowed) {
            $exclusions = [];
            foreach ($snapshot->candidates as $candidate) {
                if ($candidate->eligible()) {
                    $exclusions[$candidate->routeId->value] = 'request_origin_mismatch';
                }
            }

            return new SelectionResult($snapshot, null, null, 'configuration_hold', $exclusions);
        }
        $candidates = array_values(array_filter($snapshot->candidates, static fn (Candidate $c): bool => $c->eligible()));
        $exclusions = [];
        if ($this->extensions !== null) {
            $eligibleRoutes = [];
            foreach ($candidates as $candidate) {
                if ($candidate->route !== null) {
                    $eligibleRoutes[] = $candidate->route;
                }
            }
            $routes = $this->extensions->filter($eligibleRoutes, $request, $this->filterIds);
            $ids = array_map(static fn (Route $route): string => $route->id->value, $routes);
            foreach ($candidates as $candidate) {
                if (! in_array($candidate->routeId->value, $ids, true)) {
                    $exclusions[$candidate->routeId->value] = 'route_filter';
                }
            }
            $candidates = array_values(array_filter($candidates, static fn (Candidate $candidate): bool => in_array($candidate->routeId->value, $ids, true)));
        }
        $tried = [];
        foreach ($context->triedRouteIds as $id) {
            $tried[$id->value] = true;
        }
        if (count($tried) >= min($context->maxDistinctRoutes, $policy->timeouts->maxDistinctRoutes)) {
            foreach ($candidates as $candidate) {
                if (! isset($tried[$candidate->routeId->value])) {
                    $exclusions[$candidate->routeId->value] = 'distinct_route_limit';
                }
            }
            $candidates = array_values(array_filter($candidates, static fn (Candidate $c): bool => isset($tried[$c->routeId->value])));
        }
        if ($context->preferUntried) {
            $fresh = array_values(array_filter($candidates, static fn (Candidate $c): bool => ! isset($tried[$c->routeId->value])));
            if ($fresh !== []) {
                foreach ($candidates as $candidate) {
                    if (isset($tried[$candidate->routeId->value])) {
                        $exclusions[$candidate->routeId->value] = 'prefer_untried';
                    }
                }
                $candidates = $fresh;
            }
        } elseif ($context->previousRouteId !== null) {
            $previous = array_values(array_filter($candidates, static fn (Candidate $c): bool => $c->routeId->equals($context->previousRouteId)));
            if ($previous !== []) {
                foreach ($candidates as $candidate) {
                    if (! $candidate->routeId->equals($context->previousRouteId)) {
                        $exclusions[$candidate->routeId->value] = 'prefer_previous';
                    }
                }
                $candidates = $previous;
            }
        }
        $selected = $this->strategies->get($policy->strategyKey)->select($candidates, $request, $context);
        if ($selected === null) {
            foreach ($candidates as $candidate) {
                $exclusions[$candidate->routeId->value] = 'strategy_no_selection';
            }

            return new SelectionResult($snapshot, null, null, 'no_eligible_route', $exclusions);
        }
        $approved = null;
        $selectedReady = false;
        foreach ($candidates as $candidate) {
            if ($candidate->routeId->equals($selected->id)) {
                $approved = $candidate->route;
                $selectedReady = $candidate->budgetReady;
                break;
            }
        }
        if ($approved === null) {
            throw new InvalidArgumentException('Strategy selected a route outside the approved candidates.');
        }
        foreach ($candidates as $candidate) {
            if (! $candidate->routeId->equals($approved->id)) {
                $exclusions[$candidate->routeId->value] = $policy->strategyKey === 'budget_aware_random' && $selectedReady && ! $candidate->budgetReady
                    ? 'budget_not_ready'
                    : 'strategy_not_selected';
            }
        }

        return new SelectionResult($snapshot, $approved, new RouteSelection($approved->id, $approved->kind, $profileId,
            $approved->endpointId, $approved->credentialVersion, $approved->egress, $approved->independenceGroup), 'selected', $exclusions);
    }
}
