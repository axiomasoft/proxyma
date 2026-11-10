<?php

declare(strict_types=1);

namespace Proxyma\Domain\Outcomes;

use DateTimeImmutable;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Execution\BlockedReason;
use Proxyma\Domain\Execution\DeferralReason;
use Proxyma\Domain\Execution\OutcomeDecision;
use Proxyma\Domain\Execution\TransportFailure;
use Proxyma\Domain\Health\HealthAttribution;

/**
 * Технический классификатор физических попыток (AttemptFacts -> TechnicalClassification).
 * Отделяет транспортные/прокси/TLS/cooldown факты от доменного решения вызывающего кода.
 */
final readonly class TechnicalOutcomeClassifier
{
    private const array REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    private const array TRANSIENT_SERVER_STATUSES = [502, 503, 504];

    public function __construct(
        private RetryAfterParser $retryAfterParser = new RetryAfterParser,
    ) {}

    public function classify(
        AttemptFacts $facts,
        DateTimeImmutable $now,
        bool $wafRateSignalEnabled = false,
        bool $blockExcessiveRetryAfter = false,
    ): TechnicalClassification {
        $canRetry = $facts->idempotency->allowsAutomaticRetry() && $facts->bodyReplayable;

        if ($facts->failure !== null && ! in_array($facts->httpStatus, [407, 429], true)) {
            return $this->classifyTransportFailure($facts, $now, $canRetry);
        }

        $status = $facts->httpStatus ?? 200;

        if (in_array($status, self::REDIRECT_STATUSES, true)) {
            return new TechnicalClassification(
                facts: $facts,
                failureKind: TechnicalFailureKind::HttpResponse,
                healthAttribution: HealthAttribution::RouteSuccess,
                defaultDecision: OutcomeDecision::Accept,
                businessMeaning: 'http_redirect_'.$status,
                isRedirect: true,
            );
        }

        if ($status === 407) {
            if ($facts->routeKind === RouteKind::Proxy) {
                return new TechnicalClassification(
                    facts: $facts,
                    failureKind: TechnicalFailureKind::ProxyAuth,
                    healthAttribution: HealthAttribution::CredentialFailure,
                    defaultDecision: $canRetry ? OutcomeDecision::RetryDifferentRoute : OutcomeDecision::Block,
                    businessMeaning: 'proxy_auth_required',
                    blockedReason: BlockedReason::CredentialHold,
                    holdsProxyCredential: true,
                    forbidsSameRouteRetry: true,
                    forbidsAnyRetry: ! $canRetry,
                );
            }

            return new TechnicalClassification(
                facts: $facts,
                failureKind: TechnicalFailureKind::HttpResponse,
                healthAttribution: HealthAttribution::RouteSuccess,
                defaultDecision: OutcomeDecision::Accept,
                businessMeaning: 'http_407_direct',
            );
        }

        if ($status === 401) {
            return new TechnicalClassification(
                facts: $facts,
                failureKind: TechnicalFailureKind::HttpResponse,
                healthAttribution: HealthAttribution::RouteSuccess,
                defaultDecision: OutcomeDecision::Accept,
                businessMeaning: 'origin_unauthorized',
                holdsProxyCredential: false,
            );
        }

        if ($status === 404) {
            return new TechnicalClassification(
                facts: $facts,
                failureKind: TechnicalFailureKind::HttpResponse,
                healthAttribution: HealthAttribution::RouteSuccess,
                defaultDecision: OutcomeDecision::Accept,
                businessMeaning: 'origin_not_found',
            );
        }

        if ($status === 429) {
            $advice = $this->retryAfterParser->parse($facts->retryAfterHeader, $now);
            if ($advice->isExcessive && $blockExcessiveRetryAfter) {
                return new TechnicalClassification(
                    facts: $facts,
                    failureKind: TechnicalFailureKind::HttpResponse,
                    healthAttribution: HealthAttribution::TargetFailure,
                    defaultDecision: OutcomeDecision::Block,
                    businessMeaning: 'origin_rate_limit_excessive',
                    deferralReason: DeferralReason::RateLimit,
                    blockedReason: BlockedReason::PolicyReview,
                    cooldownUntil: $advice->retryAt,
                    retryAfterAdvice: $advice,
                    extendsOriginCooldown: true,
                    forbidsSameRouteRetry: true,
                    forbidsRouteRotation: true,
                    forbidsAnyRetry: true,
                    isMandatorySecurityBlock: true,
                );
            }

            return new TechnicalClassification(
                facts: $facts,
                failureKind: TechnicalFailureKind::HttpResponse,
                healthAttribution: HealthAttribution::TargetFailure,
                defaultDecision: OutcomeDecision::Defer,
                businessMeaning: 'origin_rate_limited',
                deferralReason: DeferralReason::RateLimit,
                cooldownUntil: $advice->retryAt,
                retryAfterAdvice: $advice,
                extendsOriginCooldown: true,
                forbidsSameRouteRetry: true,
                forbidsRouteRotation: true,
                forbidsAnyRetry: true,
            );
        }

        if ($status === 403) {
            if ($wafRateSignalEnabled) {
                $advice = $this->retryAfterParser->parse($facts->retryAfterHeader, $now);

                return new TechnicalClassification(
                    facts: $facts,
                    failureKind: TechnicalFailureKind::HttpResponse,
                    healthAttribution: HealthAttribution::RouteTargetFailure,
                    defaultDecision: OutcomeDecision::Defer,
                    businessMeaning: 'target_waf_hold',
                    deferralReason: DeferralReason::TargetCooldown,
                    cooldownUntil: $advice->retryAt,
                    retryAfterAdvice: $advice,
                    appliesWafHold: true,
                    forbidsSameRouteRetry: true,
                );
            }

            return new TechnicalClassification(
                facts: $facts,
                failureKind: TechnicalFailureKind::HttpResponse,
                healthAttribution: HealthAttribution::RouteSuccess,
                defaultDecision: OutcomeDecision::Accept,
                businessMeaning: 'origin_forbidden',
            );
        }

        if (in_array($status, self::TRANSIENT_SERVER_STATUSES, true)) {
            $advice = $facts->retryAfterHeader !== null
                ? $this->retryAfterParser->parse($facts->retryAfterHeader, $now)
                : null;

            if ($advice !== null && $advice->isExcessive && $blockExcessiveRetryAfter) {
                return new TechnicalClassification(
                    facts: $facts,
                    failureKind: TechnicalFailureKind::HttpResponse,
                    healthAttribution: HealthAttribution::TargetFailure,
                    defaultDecision: OutcomeDecision::Block,
                    businessMeaning: 'origin_unavailable_excessive_retry_after',
                    deferralReason: DeferralReason::TargetCooldown,
                    blockedReason: BlockedReason::PolicyReview,
                    cooldownUntil: $advice->retryAt,
                    retryAfterAdvice: $advice,
                    forbidsAnyRetry: true,
                    isMandatorySecurityBlock: true,
                );
            }

            return new TechnicalClassification(
                facts: $facts,
                failureKind: TechnicalFailureKind::HttpResponse,
                healthAttribution: HealthAttribution::TargetFailure,
                defaultDecision: $canRetry ? OutcomeDecision::RetrySameRoute : OutcomeDecision::Accept,
                businessMeaning: 'origin_transient_'.$status,
                deferralReason: DeferralReason::TargetCooldown,
                cooldownUntil: $advice?->retryAt,
                retryAfterAdvice: $advice,
                forbidsAnyRetry: ! $canRetry,
            );
        }

        if ($status >= 500) {
            return new TechnicalClassification(
                facts: $facts,
                failureKind: TechnicalFailureKind::HttpResponse,
                healthAttribution: HealthAttribution::TargetFailure,
                defaultDecision: OutcomeDecision::Accept,
                businessMeaning: 'origin_server_error_'.$status,
                forbidsAnyRetry: ! $canRetry,
            );
        }

        return new TechnicalClassification(
            facts: $facts,
            failureKind: TechnicalFailureKind::HttpResponse,
            healthAttribution: HealthAttribution::RouteSuccess,
            defaultDecision: OutcomeDecision::Accept,
            businessMeaning: 'http_'.$status,
        );
    }

    private function classifyTransportFailure(
        AttemptFacts $facts,
        DateTimeImmutable $now,
        bool $canRetry,
    ): TechnicalClassification {
        return match ($facts->failure) {
            TransportFailure::ProxyAuth => new TechnicalClassification(
                facts: $facts,
                failureKind: TechnicalFailureKind::ProxyAuth,
                healthAttribution: HealthAttribution::CredentialFailure,
                defaultDecision: $canRetry ? OutcomeDecision::RetryDifferentRoute : OutcomeDecision::Block,
                businessMeaning: 'proxy_auth_failure',
                blockedReason: BlockedReason::CredentialHold,
                holdsProxyCredential: true,
                forbidsSameRouteRetry: true,
                forbidsAnyRetry: ! $canRetry,
            ),
            TransportFailure::ProxyConnect => new TechnicalClassification(
                facts: $facts,
                failureKind: TechnicalFailureKind::ProxyConnect,
                healthAttribution: HealthAttribution::RouteFailure,
                defaultDecision: $canRetry ? OutcomeDecision::RetryDifferentRoute : OutcomeDecision::Defer,
                businessMeaning: 'proxy_connect_failure',
                deferralReason: DeferralReason::NoHealthyRoute,
                cooldownUntil: $canRetry ? null : $this->retryAfterParser->parse(null, $now)->retryAt,
                forbidsSameRouteRetry: true,
                forbidsAnyRetry: ! $canRetry,
            ),
            TransportFailure::Tls => new TechnicalClassification(
                facts: $facts,
                failureKind: TechnicalFailureKind::TlsFailure,
                healthAttribution: HealthAttribution::RouteTargetFailure,
                defaultDecision: OutcomeDecision::Block,
                businessMeaning: 'tls_verification_failed',
                blockedReason: BlockedReason::ConfigurationHold,
                forbidsAnyRetry: true,
                isMandatorySecurityBlock: true,
            ),
            TransportFailure::Timeout => new TechnicalClassification(
                facts: $facts,
                failureKind: TechnicalFailureKind::OriginTimeout,
                healthAttribution: HealthAttribution::RouteTargetFailure,
                defaultDecision: $canRetry ? OutcomeDecision::RetryDifferentRoute : OutcomeDecision::Block,
                businessMeaning: 'origin_timeout',
                deferralReason: DeferralReason::NoHealthyRoute,
                blockedReason: BlockedReason::PolicyReview,
                forbidsAnyRetry: ! $canRetry,
                isAmbiguousAfterAuthorization: ! $facts->idempotency->allowsAutomaticRetry(),
            ),
            TransportFailure::Truncated => new TechnicalClassification(
                facts: $facts,
                failureKind: TechnicalFailureKind::TruncatedResponse,
                healthAttribution: HealthAttribution::RouteTargetFailure,
                defaultDecision: $canRetry ? OutcomeDecision::RetryDifferentRoute : OutcomeDecision::Block,
                businessMeaning: 'truncated_response',
                deferralReason: DeferralReason::NoHealthyRoute,
                blockedReason: BlockedReason::PolicyReview,
                forbidsAnyRetry: ! $canRetry,
                isAmbiguousAfterAuthorization: ! $facts->idempotency->allowsAutomaticRetry(),
            ),
            TransportFailure::Connect => new TechnicalClassification(
                facts: $facts,
                failureKind: TechnicalFailureKind::OriginConnect,
                healthAttribution: HealthAttribution::RouteTargetFailure,
                defaultDecision: $canRetry ? OutcomeDecision::RetryDifferentRoute : OutcomeDecision::Block,
                businessMeaning: 'origin_connect_failure',
                deferralReason: DeferralReason::NoHealthyRoute,
                blockedReason: BlockedReason::PolicyReview,
                forbidsAnyRetry: ! $canRetry,
                isAmbiguousAfterAuthorization: ! $facts->idempotency->allowsAutomaticRetry(),
            ),
            TransportFailure::ByteLimit => new TechnicalClassification(
                facts: $facts,
                failureKind: TechnicalFailureKind::ByteLimitExceeded,
                healthAttribution: HealthAttribution::None,
                defaultDecision: OutcomeDecision::Block,
                businessMeaning: 'response_byte_limit_exceeded',
                blockedReason: BlockedReason::PolicyReview,
                forbidsAnyRetry: true,
                isMandatorySecurityBlock: true,
            ),
            TransportFailure::Protocol => new TechnicalClassification(
                facts: $facts,
                failureKind: TechnicalFailureKind::ProtocolViolation,
                healthAttribution: HealthAttribution::RouteTargetFailure,
                defaultDecision: OutcomeDecision::Block,
                businessMeaning: 'protocol_violation',
                blockedReason: BlockedReason::PolicyReview,
                isAmbiguousAfterAuthorization: ! $facts->idempotency->allowsAutomaticRetry(),
                forbidsAnyRetry: true,
                isMandatorySecurityBlock: true,
            ),
            TransportFailure::Storage => new TechnicalClassification(
                facts: $facts,
                failureKind: TechnicalFailureKind::StorageFailure,
                healthAttribution: HealthAttribution::None,
                defaultDecision: OutcomeDecision::Defer,
                businessMeaning: 'storage_unavailable',
                deferralReason: DeferralReason::DependencyUnavailable,
                cooldownUntil: $this->retryAfterParser->parse(null, $now)->retryAt,
                forbidsAnyRetry: true,
                isMandatorySecurityBlock: true,
            ),
            TransportFailure::Configuration => new TechnicalClassification(
                facts: $facts,
                failureKind: TechnicalFailureKind::ConfigurationFailure,
                healthAttribution: HealthAttribution::None,
                defaultDecision: OutcomeDecision::Block,
                businessMeaning: 'transport_configuration_hold',
                blockedReason: BlockedReason::ConfigurationHold,
                forbidsAnyRetry: true,
                isMandatorySecurityBlock: true,
            ),
            TransportFailure::Cancelled, null => new TechnicalClassification(
                facts: $facts,
                failureKind: TechnicalFailureKind::Cancelled,
                healthAttribution: HealthAttribution::None,
                defaultDecision: OutcomeDecision::Block,
                businessMeaning: 'attempt_cancelled',
                blockedReason: BlockedReason::OwnerLost,
                forbidsAnyRetry: true,
                isMandatorySecurityBlock: true,
            ),
        };
    }
}
