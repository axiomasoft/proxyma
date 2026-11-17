<?php

declare(strict_types=1);

namespace Proxyma\Application\Execution;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Packman\Attributes\Api;
use Proxyma\Contracts\Execution\OutcomePolicy;
use Proxyma\Domain\Execution\AttemptResult;
use Proxyma\Domain\Execution\BlockedReason;
use Proxyma\Domain\Execution\DeferralReason;
use Proxyma\Domain\Execution\OutcomeClassification;
use Proxyma\Domain\Execution\OutcomeDecision;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Outcomes\AttemptFacts;
use Proxyma\Domain\Outcomes\CallerOutcomeAction;
use Proxyma\Domain\Outcomes\DefaultOutcomePolicy;
use Proxyma\Domain\Outcomes\OutcomePolicyTier;
use Proxyma\Domain\Outcomes\ResolvedOutcome;
use Proxyma\Domain\Outcomes\RetryAfterParser;
use Proxyma\Domain\Outcomes\TechnicalClassification;
use Proxyma\Domain\Outcomes\TechnicalOutcomeClassifier;
use Throwable;

/**
 * Реестр политик исхода (OutcomePolicyRegistry) с иерархией приоритетов:
 * security+ownership -> project -> panel -> default.
 *
 * Отклоняет конфликты равного приоритета при freeze() и гарантирует, что пользовательская
 * политика не может изменить AttemptFacts, отменить атрибуцию здоровья, пропустить квоты,
 * подавить блокировки TLS/407 или обойти origin cooldown на 429.
 *
 * @phpstan-type OutcomePolicyCallable Closure(AttemptFacts, AttemptResult, WireRequest, TechnicalClassification): (OutcomeClassification|CallerOutcomeAction)
 * @phpstan-type OutcomePolicyEntry array{
 *     policy: OutcomePolicy|OutcomePolicyCallable,
 *     tier: OutcomePolicyTier,
 *     priority: int,
 *     wafRateSignal: bool,
 *     blockExcessiveRetryAfter: bool,
 * }
 */
#[Api(since: '1.0')]
final class OutcomePolicyRegistry
{
    /** @var array<string, list<OutcomePolicyEntry>> */
    private array $registrations = [];

    private bool $frozen = false;

    private readonly TechnicalOutcomeClassifier $classifier;

    private readonly RetryAfterParser $retryAfterParser;

    public function __construct(
        ?TechnicalOutcomeClassifier $classifier = null,
        ?RetryAfterParser $retryAfterParser = null,
    ) {
        $this->retryAfterParser = $retryAfterParser ?? new RetryAfterParser;
        $this->classifier = $classifier ?? new TechnicalOutcomeClassifier($this->retryAfterParser);

        $this->register(
            key: 'default',
            policy: new DefaultOutcomePolicy($this->classifier),
            tier: OutcomePolicyTier::Default,
            priority: 0,
        );
    }

    /** @param OutcomePolicy|OutcomePolicyCallable $policy */
    public function register(
        string $key,
        OutcomePolicy|Closure $policy,
        OutcomePolicyTier $tier = OutcomePolicyTier::Project,
        int $priority = 0,
        bool $wafRateSignal = false,
        bool $blockExcessiveRetryAfter = false,
    ): self {
        if ($this->frozen) {
            throw new LogicException('Cannot register outcome policies after the registry has been frozen.');
        }

        if (preg_match('/^[a-z0-9][a-z0-9_.:-]{0,127}$/D', $key) !== 1) {
            throw new InvalidArgumentException("Invalid outcome policy key '{$key}'.");
        }

        $this->registrations[$key] ??= [];
        $this->registrations[$key][] = [
            'policy' => $policy,
            'tier' => $tier,
            'priority' => $priority,
            'wafRateSignal' => $wafRateSignal,
            'blockExcessiveRetryAfter' => $blockExcessiveRetryAfter,
        ];

        return $this;
    }

    public function freeze(): self
    {
        foreach ($this->registrations as $key => $entries) {
            $this->assertNoEqualPriorityConflict($key, $entries);
            if ($key !== 'default') {
                $this->assertNoEqualPriorityConflict($key, [...array_values(array_filter($this->registrations['default'],
                    static fn (array $e): bool => $e['tier'] === OutcomePolicyTier::SecurityOwnership)), ...$entries]);
            }
        }

        $this->frozen = true;

        return $this;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    /** @return OutcomePolicy|OutcomePolicyCallable */
    public function resolve(string $key): OutcomePolicy|Closure
    {
        return $this->resolveEntry($key)['policy'];
    }

    /**
     * Interpretation is copied into authorization; completion never re-reads this registry.
     *
     * @return array{key: string, revision: int, waf_rate_signal: bool}
     */
    public function healthInterpretation(string $key): array
    {
        $entry = $this->resolveEntry($key);
        $security = $this->highestSecurityEntry($key);

        return ['key' => $key, 'revision' => 1,
            'waf_rate_signal' => $entry['wafRateSignal'] || ($security['wafRateSignal'] ?? false)];
    }

    public function evaluate(
        string $policyKey,
        AttemptFacts $facts,
        AttemptResult $attempt,
        WireRequest $request,
        DateTimeImmutable $now,
    ): ResolvedOutcome {
        $entry = $this->resolveEntry($policyKey);
        $securityEntry = $this->highestSecurityEntry($policyKey);

        $technical = $this->classifier->classify(
            facts: $facts,
            now: $now,
            wafRateSignalEnabled: $entry['wafRateSignal'] || ($securityEntry['wafRateSignal'] ?? false),
            blockExcessiveRetryAfter: $entry['blockExcessiveRetryAfter'] || ($securityEntry['blockExcessiveRetryAfter'] ?? false),
        );

        // При наличии политики уровня SecurityOwnership для ключа или 'default' она имеет высший приоритет.
        if ($securityEntry !== null && $securityEntry !== $entry) {
            $securityProposal = $this->propose($securityEntry['policy'], $facts, $attempt, $request, $technical, $now);
            if (in_array($securityProposal->decision, [OutcomeDecision::Block, OutcomeDecision::Defer], true)) {
                return $this->enforceInvariants($facts, $technical, $securityProposal, OutcomePolicyTier::SecurityOwnership, $now);
            }
        }

        $proposal = $entry['policy'] instanceof DefaultOutcomePolicy
            ? $technical->toDefaultOutcomeClassification()
            : $this->propose($entry['policy'], $facts, $attempt, $request, $technical, $now);

        return $this->enforceInvariants($facts, $technical, $proposal, $entry['tier'], $now);
    }

    /** @return OutcomePolicyEntry */
    private function resolveEntry(string $key): array
    {
        $entries = $this->registrations[$key] ?? [];
        if ($entries === []) {
            throw new LogicException("Outcome policy '{$key}' is not registered.");
        }

        $this->assertNoEqualPriorityConflict($key, $entries);

        usort($entries, static function (array $a, array $b): int {
            $tierCompare = $b['tier']->rank() <=> $a['tier']->rank();
            if ($tierCompare !== 0) {
                return $tierCompare;
            }

            return $b['priority'] <=> $a['priority'];
        });

        return $entries[0];
    }

    /** @return ?OutcomePolicyEntry */
    private function highestSecurityEntry(string $key): ?array
    {
        $candidates = [];
        foreach (array_unique([$key, 'default']) as $candidateKey) {
            foreach ($this->registrations[$candidateKey] ?? [] as $entry) {
                if ($entry['tier'] === OutcomePolicyTier::SecurityOwnership) {
                    $candidates[] = $entry;
                }
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);

        return $candidates[0];
    }

    /** @param list<OutcomePolicyEntry> $entries */
    private function assertNoEqualPriorityConflict(string $key, array $entries): void
    {
        $seen = [];
        foreach ($entries as $entry) {
            $slot = $entry['tier']->value.':'.$entry['priority'];
            if (isset($seen[$slot])) {
                throw new LogicException(
                    "Equal-priority outcome policy conflict for key '{$key}' at tier '{$entry['tier']->value}' and priority {$entry['priority']}.",
                );
            }
            $seen[$slot] = true;
        }
    }

    /** @param OutcomePolicy|OutcomePolicyCallable $policy */
    private function propose(
        OutcomePolicy|Closure $policy,
        AttemptFacts $facts,
        AttemptResult $attempt,
        WireRequest $request,
        TechnicalClassification $technical,
        DateTimeImmutable $now,
    ): OutcomeClassification {
        try {
            $proposal = $policy instanceof Closure
                ? $policy($facts, $attempt, $request, $technical)
                : $policy->classify($request, $attempt);
        } catch (Throwable) {
            return new OutcomeClassification(OutcomeDecision::Block, 'outcome_policy_failed', $technical->healthAttribution);
        }
        if ($proposal instanceof OutcomeClassification) {
            return $proposal;
        }

        return new OutcomeClassification($proposal->toOutcomeDecision(),
            $proposal === CallerOutcomeAction::RequireReview ? 'caller_require_review' : 'caller_'.$proposal->value,
            $technical->healthAttribution,
            $proposal === CallerOutcomeAction::DeferUntil
                ? ($technical->cooldownUntil ?? $this->retryAfterParser->parse(null, $now)->retryAt) : null);
    }

    private function enforceInvariants(
        AttemptFacts $facts,
        TechnicalClassification $technical,
        OutcomeClassification $proposal,
        OutcomePolicyTier $winningTier,
        DateTimeImmutable $now,
    ): ResolvedOutcome {
        // 1. Обязательные блокировки безопасности (TLS, ByteLimit, Protocol, Cancelled, Configuration, Excessive Retry-After)
        // никогда не подавляются пользовательской политикой.
        if ($technical->isMandatorySecurityBlock) {
            $classification = $technical->toDefaultOutcomeClassification();

            return new ResolvedOutcome(
                facts: $facts,
                technical: $technical,
                classification: $classification,
                action: CallerOutcomeAction::fromOutcomeDecision($classification->decision, $classification->businessMeaning),
                winningTier: OutcomePolicyTier::SecurityOwnership,
                deferralReason: $technical->deferralReason,
                blockedReason: $technical->blockedReason,
            );
        }

        // 2. Отказ прокси-авторизации (407 на прокси-маршруте или TransportFailure::ProxyAuth):
        // удерживает версию кредов и допускает повтор ТОЛЬКО через другой разрешённый маршрут профиля.
        if ($technical->holdsProxyCredential) {
            $decision = match ($proposal->decision) {
                OutcomeDecision::Block => OutcomeDecision::Block,
                OutcomeDecision::Defer => OutcomeDecision::Defer,
                default => $facts->idempotency->allowsAutomaticRetry() && $facts->bodyReplayable
                    ? OutcomeDecision::RetryDifferentRoute : OutcomeDecision::Block,
            };

            $classification = new OutcomeClassification(
                decision: $decision,
                businessMeaning: $technical->businessMeaning,
                healthAttribution: $technical->healthAttribution,
                retryAfter: $decision === OutcomeDecision::Defer ? $proposal->retryAfter : null,
            );

            return new ResolvedOutcome(
                facts: $facts,
                technical: $technical,
                classification: $classification,
                action: CallerOutcomeAction::fromOutcomeDecision($decision, $technical->businessMeaning),
                winningTier: OutcomePolicyTier::SecurityOwnership,
                deferralReason: $technical->deferralReason,
                blockedReason: BlockedReason::CredentialHold,
            );
        }

        // 3. HTTP 429 (Origin Rate Limit):
        // никогда не вращает маршруты в обход origin cooldown и не допускает немедленный retry/accept.
        if ($technical->extendsOriginCooldown) {
            $cooldown = $technical->cooldownUntil ?? $this->retryAfterParser->parse(null, $now)->retryAt;
            if ($proposal->retryAfter !== null && $proposal->retryAfter->isAfter($cooldown->value)) {
                $cooldown = $proposal->retryAfter;
            }

            if ($proposal->decision === OutcomeDecision::Block) {
                $blockedReason = str_contains($proposal->businessMeaning, 'review')
                    ? BlockedReason::PolicyReview
                    : ($technical->blockedReason ?? BlockedReason::PolicyReview);
                $classification = new OutcomeClassification(
                    decision: OutcomeDecision::Block,
                    businessMeaning: $proposal->businessMeaning,
                    healthAttribution: $technical->healthAttribution,
                );

                return new ResolvedOutcome(
                    facts: $facts,
                    technical: $technical,
                    classification: $classification,
                    action: CallerOutcomeAction::fromOutcomeDecision(OutcomeDecision::Block, $proposal->businessMeaning),
                    winningTier: $winningTier,
                    deferralReason: DeferralReason::RateLimit,
                    blockedReason: $blockedReason,
                );
            }

            $classification = new OutcomeClassification(
                decision: OutcomeDecision::Defer,
                businessMeaning: $technical->businessMeaning,
                healthAttribution: $technical->healthAttribution,
                retryAfter: $cooldown,
            );

            return new ResolvedOutcome(
                facts: $facts,
                technical: $technical,
                classification: $classification,
                action: CallerOutcomeAction::DeferUntil,
                winningTier: OutcomePolicyTier::SecurityOwnership,
                deferralReason: DeferralReason::RateLimit,
            );
        }

        // 4. Неидемпотентная запись (AmbiguousWrite) после авторизации с неопределённым исходом транспорта
        // никогда не повторяется автоматически.
        if ($technical->isAmbiguousAfterAuthorization) {
            $classification = new OutcomeClassification(
                decision: OutcomeDecision::Block,
                businessMeaning: $technical->businessMeaning,
                healthAttribution: $technical->healthAttribution,
            );

            return new ResolvedOutcome(
                facts: $facts,
                technical: $technical,
                classification: $classification,
                action: CallerOutcomeAction::Block,
                winningTier: OutcomePolicyTier::SecurityOwnership,
                deferralReason: $technical->deferralReason,
                blockedReason: $technical->blockedReason ?? BlockedReason::PolicyReview,
            );
        }

        // 5. WAF-удержание на 403 при включённой WAF-интерпретации не может быть превращено в успешный ответ.
        if ($technical->appliesWafHold && in_array($proposal->decision, [OutcomeDecision::Accept, OutcomeDecision::RetrySameRoute, OutcomeDecision::RetryDifferentRoute], true)) {
            $classification = $technical->toDefaultOutcomeClassification();

            return new ResolvedOutcome(
                facts: $facts,
                technical: $technical,
                classification: $classification,
                action: CallerOutcomeAction::fromOutcomeDecision($classification->decision, $classification->businessMeaning),
                winningTier: $winningTier,
                deferralReason: $technical->deferralReason ?? DeferralReason::TargetCooldown,
                blockedReason: $technical->blockedReason,
            );
        }

        $decision = $proposal->decision;

        // 6. Запрет повтора при неидемпотентном методе или неповторяемом потоке тела.
        if (
            in_array($decision, [OutcomeDecision::RetrySameRoute, OutcomeDecision::RetryDifferentRoute], true)
            && ($technical->forbidsAnyRetry || ! $facts->idempotency->allowsAutomaticRetry() || ! $facts->bodyReplayable)
        ) {
            $decision = $facts->delivered && $facts->bodyReplayable ? OutcomeDecision::Accept : OutcomeDecision::Block;
        }

        // 7. Запрет повтора по тому же маршруту при сбое прокси-соединения.
        if ($decision === OutcomeDecision::RetrySameRoute && $technical->forbidsSameRouteRetry) {
            $decision = OutcomeDecision::RetryDifferentRoute;
        }

        // 8. При отсутствии HTTP-ответа (транспортный сбой) нельзя вернуть Accept.
        if (! $facts->delivered && $decision === OutcomeDecision::Accept) {
            $decision = $technical->defaultDecision;
        }

        $retryAfter = $proposal->retryAfter ?? $technical->cooldownUntil;
        if ($decision === OutcomeDecision::Defer && $retryAfter === null) {
            $retryAfter = $this->retryAfterParser->parse($facts->retryAfterHeader, $now)->retryAt;
        }

        // Атрибуция здоровья всегда берётся из технической классификации (не может быть отменена политикой).
        $finalClassification = new OutcomeClassification(
            decision: $decision,
            businessMeaning: $proposal->businessMeaning,
            healthAttribution: $technical->healthAttribution,
            retryAfter: $decision === OutcomeDecision::Defer ? $retryAfter : $proposal->retryAfter,
        );

        $blockedReason = null;
        if ($decision === OutcomeDecision::Block) {
            $blockedReason = str_contains($proposal->businessMeaning, 'review')
                ? BlockedReason::PolicyReview
                : ($technical->blockedReason ?? BlockedReason::PolicyReview);
        }

        $deferralReason = null;
        if ($decision === OutcomeDecision::Defer) {
            $deferralReason = $technical->deferralReason ?? DeferralReason::TargetCooldown;
        }

        return new ResolvedOutcome(
            facts: $facts,
            technical: $technical,
            classification: $finalClassification,
            action: CallerOutcomeAction::fromOutcomeDecision($decision, $proposal->businessMeaning),
            winningTier: $winningTier,
            deferralReason: $deferralReason,
            blockedReason: $blockedReason,
        );
    }
}
