<?php

declare(strict_types=1);

namespace Proxyma\Application\Execution;

use Closure;
use InvalidArgumentException;
use LogicException;
use Packman\Attributes\Api;
use Proxyma\Contracts\Execution\FiniteExecutionPort;
use Proxyma\Domain\Execution as X;

/** Finite chain of immutable physical operations, including an explicit mapped target per hop. */
#[Api(since: '1.0')]
final readonly class FiniteOutboundExecutor
{
    /** @param (Closure(int): void)|null $sleepUs */
    public function __construct(private ?Closure $sleepUs = null) {}

    public function execute(X\OutboundRequest $initial, FiniteExecutionPort $port): X\ExecutionResult
    {
        $url = $initial->url;
        $target = $initial->targetId;
        $headers = $initial->headers;
        $visited = [$url->reveal() => true];
        $ids = $tried = [];
        $previous = null;
        $preferUntried = false;
        $attempt = 1;
        $hops = 0;
        $sends = 0;
        $limits = $initial->timeouts;
        $last = null;
        $maximum = $initial->maxAutomaticAttempts() * ($limits->maxRedirects + 1);
        try {
            while ($sends < $maximum) {
                if ($initial->sendLimit !== null && $sends >= $initial->sendLimit) {
                    return new X\ExecutionBlocked(X\BlockedReason::PolicyReview, attemptIds: $ids);
                }
                if (! $port->canStart(new X\Duration($limits->totalTimeoutMs + $limits->cleanupMarginMs))) {
                    return $this->deferred($port, X\DeferralReason::SliceExhausted, $ids, $last);
                }
                $id = $sends === 0 ? $initial->operationId : X\Uuid::random();
                $request = new X\OutboundRequest($id, $target, $initial->method, $url, $limits, $initial->idempotency,
                    $headers, $initial->body, $initial->trafficKey, responseProfile: $initial->responseProfile,
                    outcomePolicyKey: $initial->outcomePolicyKey);
                $physical = $port->executeAttempt($request,
                    new X\RouteSelectionContext($tried, $previous, $preferUntried, $limits->maxDistinctRoutes), $attempt, $hops);
                if ($physical instanceof X\ExecutionResult) {
                    $previousResponse = $last;
                    if (! $physical instanceof X\ExecutionDeferred || $physical->lastDeliveredResponse !== null) {
                        $this->close($previousResponse);
                        $previousResponse = null;
                    }
                    $last = null;

                    return match (true) {
                        $physical instanceof X\ExecutionDeferred => new X\ExecutionDeferred($physical->reason, $physical->retryAt,
                            [...$ids, ...$physical->attemptIds], $physical->lastDeliveredResponse ?? $previousResponse),
                        $physical instanceof X\ExecutionBlocked => new X\ExecutionBlocked($physical->reason, $physical->safeDetails,
                            [...$ids, ...$physical->attemptIds]),
                        default => $physical,
                    };
                }
                if (! $physical->physicalAttemptId->equals($id)) {
                    throw new LogicException('Physical operation correlation mismatch.');
                }
                $sends++;
                $ids[] = $id;
                if (! array_any($tried, static fn (X\Uuid $route): bool => $route->equals($physical->routeId))) {
                    $tried[] = $physical->routeId;
                }
                $previous = $physical->routeId;
                $limits = $this->intersect($limits, $physical->timeouts);
                if ($attempt > $limits->maxAttempts || $hops > $limits->maxRedirects || count($tried) > $limits->maxDistinctRoutes) {
                    throw new LogicException('Physical port exceeded the prepared finite bounds.');
                }
                $this->close($last);
                $last = $physical->result->response;
                if ($physical->unknown !== null) {
                    return $physical->unknown;
                }
                $outcome = $physical->outcome;
                $decision = $outcome->decision();
                if ($outcome->isRedirect() && $decision === X\OutcomeDecision::Accept) {
                    $location = $last?->header('location');
                    if ($location === null || $hops >= $limits->maxRedirects || $initial->body !== null || ! in_array($initial->method, ['GET', 'HEAD'], true)) {
                        return new X\ExecutionBlocked(X\BlockedReason::UrlPolicyDenied, attemptIds: $ids);
                    }
                    try {
                        $next = (new RedirectUrlResolver)->resolve($url, $location);
                    } catch (InvalidArgumentException) {
                        return new X\ExecutionBlocked(X\BlockedReason::UrlPolicyDenied, attemptIds: $ids);
                    }
                    if (($url->origin->scheme === 'https' && $next->origin->scheme !== 'https') || isset($visited[$next->reveal()])) {
                        return new X\ExecutionBlocked(X\BlockedReason::UrlPolicyDenied, attemptIds: $ids);
                    }
                    $mapped = $port->redirectTarget($target, $next);
                    if ($mapped === null) {
                        return new X\ExecutionBlocked(X\BlockedReason::UrlPolicyDenied, attemptIds: $ids);
                    }
                    if (! $url->origin->equals($next->origin)) {
                        $headers = $headers->acrossOrigin(preserveContentType: false);
                    }
                    $target = $mapped;
                    $url = $next;
                    $visited[$url->reveal()] = true;
                    $hops++;
                    $this->close($last);
                    $last = null;

                    continue;
                }
                if ($decision === X\OutcomeDecision::Accept && $last !== null) {
                    $response = $last;
                    $last = null;

                    return new X\ExecutionSucceeded($response, $ids, $outcome->classification);
                }
                if ($decision === X\OutcomeDecision::Defer) {
                    $response = $last;
                    $last = null;

                    return new X\ExecutionDeferred($outcome->deferralReason ?? X\DeferralReason::DependencyUnavailable,
                        $outcome->retryAfter() ?? new X\RetryAt($port->now()->modify('+1 second')), $ids, $response);
                }
                if (! in_array($decision, [X\OutcomeDecision::RetrySameRoute, X\OutcomeDecision::RetryDifferentRoute], true)) {
                    return new X\ExecutionBlocked($outcome->blockedReason ?? X\BlockedReason::PolicyReview, attemptIds: $ids);
                }
                if (! $initial->idempotency->allowsAutomaticRetry() || $attempt >= $limits->maxAttempts) {
                    return $this->deferred($port, X\DeferralReason::AttemptsExhausted, $ids, $last);
                }
                $preferUntried = $decision === X\OutcomeDecision::RetryDifferentRoute;
                $delay = $limits->retryDelaysMs[$attempt - 1] ?? 0;
                while ($delay > 0) {
                    $chunk = min(1000, $delay);
                    if (! $port->canStart(new X\Duration($delay + $limits->totalTimeoutMs + $limits->cleanupMarginMs))) {
                        return $this->deferred($port, X\DeferralReason::SliceExhausted, $ids, $last);
                    }
                    $this->sleepUs === null ? usleep($chunk * 1000) : ($this->sleepUs)($chunk * 1000);
                    $delay -= $chunk;
                }
                $attempt++;
            }

            return $this->deferred($port, X\DeferralReason::AttemptsExhausted, $ids, $last);
        } finally {
            $this->close($last);
        }
    }

    private function intersect(X\RequestTimeoutPolicy $caller, X\RequestTimeoutPolicy $effective): X\RequestTimeoutPolicy
    {
        $attempts = min($caller->maxAttempts, $effective->maxAttempts);
        $total = min($caller->totalTimeoutMs, $effective->totalTimeoutMs);
        // The native resolver has already applied explicit policy overrides and disabled jitter.
        $delays = $effective->retryDelaysMs;

        return new X\RequestTimeoutPolicy(min($total, $caller->connectTimeoutMs, $effective->connectTimeoutMs),
            min($total, $caller->readTimeoutMs, $effective->readTimeoutMs), $total,
            max($caller->cleanupMarginMs, $effective->cleanupMarginMs), $attempts,
            min($attempts, $caller->maxDistinctRoutes, $effective->maxDistinctRoutes),
            min($caller->maxRedirects, $effective->maxRedirects), $delays);
    }

    /**
     * @param  list<X\Uuid>  $ids
     *
     * @param-out null $last
     */
    private function deferred(FiniteExecutionPort $port, X\DeferralReason $reason, array $ids, ?X\ResponseDescriptor &$last): X\ExecutionDeferred
    {
        $response = $last;
        $last = null;

        return new X\ExecutionDeferred($reason, new X\RetryAt($port->now()->modify('+1 second')), $ids, $response);
    }

    private function close(?X\ResponseDescriptor $response): void
    {
        if ($response?->body instanceof X\BinaryBodyArtifact) {
            $response->body->close();
        }
    }
}
