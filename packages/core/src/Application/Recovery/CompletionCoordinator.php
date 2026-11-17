<?php

declare(strict_types=1);

namespace Proxyma\Application\Recovery;

use Illuminate\Database\QueryException;
use Packman\Attributes\Api;
use Proxyma\Application\Admission\TransactionCoordinator;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Health\CooldownService;
use Proxyma\Contracts\Authorization\TenantContextResolver;
use Proxyma\Contracts\Execution\Completion;
use Proxyma\Contracts\Execution\LockedCompletionReducer;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Execution\AttemptResult;
use Proxyma\Domain\Execution\CompletionFact;
use Proxyma\Domain\Execution\ContentHash;
use Proxyma\Domain\Execution\ExecutionDeferred;
use Proxyma\Domain\Execution\LateReceiptClaim;
use Proxyma\Domain\Execution\LateReceiptOutcome;
use Proxyma\Domain\Execution\LateReceiptResult;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\PermitState;
use Proxyma\Domain\Execution\SafeUrl;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\HealthEpochMap;
use Proxyma\Domain\Outcomes\ResolvedOutcome;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\DatabaseClock;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Database\ReconcileRepository;
use Proxyma\Infrastructure\Health\ProbeClaims;
use Proxyma\Internal\Database\RowValues;
use stdClass;

#[Api(since: '1.0')]
final readonly class CompletionCoordinator implements Completion
{
    public function __construct(private TransactionCoordinator $transactions, private ReconcileRepository $repository,
        private CatalogAccess $access, private TenantContextResolver $tenants, private LockedCompletionReducer $reducer) {}

    public function record(Uuid $operationId, Uuid $physicalAttemptId, AttemptResult $result, ?ContentHash $receiptDigest = null): ?ExecutionDeferred
    {
        $summary = self::summary($result);
        $digest = hash('sha256', json_encode($summary, JSON_THROW_ON_ERROR));
        $this->persist($operationId, $physicalAttemptId, $summary, $receiptDigest->hex ?? $digest, 'local:'.$physicalAttemptId->value, null);

        return null;
    }

    public function recordOutcome(
        Uuid $operationId,
        Uuid $physicalAttemptId,
        AttemptResult $result,
        ResolvedOutcome $outcome,
    ): void {
        $summary = self::summary($result) + [
            'health_attribution' => $outcome->healthAttribution()->value,
            'outcome_decision' => $outcome->decision()->value,
            'business_meaning' => $outcome->classification->businessMeaning,
            'policy_revision' => $outcome->facts->policyRevision,
            'route_id' => $outcome->facts->routeId->value,
            'target_id' => $outcome->facts->targetId->value,
            'origin_scope_key' => $outcome->facts->origin->budgetScopeKey(),
            'credential_version' => $outcome->facts->credentialVersion,
            'holds_proxy_credential' => $outcome->technical->holdsProxyCredential,
            'extends_origin_cooldown' => $outcome->technical->extendsOriginCooldown,
            'applies_waf_hold' => $outcome->technical->appliesWafHold,
            'cooldown_until' => $outcome->retryAfter()?->value->format('Y-m-d H:i:s.uP'),
        ];
        $digest = hash('sha256', json_encode($summary, JSON_THROW_ON_ERROR));
        $this->persist($operationId, $physicalAttemptId, $summary, $outcome->facts->receiptDigest->hex !== '' ? $outcome->facts->receiptDigest->hex : $digest, 'local:'.$physicalAttemptId->value, null);
    }

    public function recordLateReceipt(LateReceiptClaim $receipt): LateReceiptResult
    {
        $summary = $receipt->verifiedResponse === null ? ['delivered' => true, 'verified_digest_only' => true]
            : self::summary(AttemptResult::delivered($receipt->verifiedResponse));

        return $this->persist($receipt->operationId, $receipt->physicalAttemptId, $summary, $receipt->receiptDigest->hex, $receipt->receiptId, $receipt->wireFingerprint);
    }

    /**
     * All descriptors and hashes are prepared before SQL; body/secret I/O never enters this closure.
     *
     * @param  array<string, mixed>  $summary
     */
    private function persist(Uuid $operation, Uuid $physical, array $summary, string $receiptDigest, string $receiptKey, ?ContentHash $wire): LateReceiptResult
    {
        $scope = $this->transactions->scope;
        $scope->assertOutsideTransaction();
        $tenant = $this->tenants->resolve()->tenant;
        $this->access->authorize($tenant, 'execution.receipt.record', $operation);
        $scope->assertSame($this->reducer->connection());
        $resultDigest = hash('sha256', json_encode($summary, JSON_THROW_ON_ERROR));
        $receiptKey = hash('sha256', $receiptKey);

        try {
            return $this->transactions->run(function ($tx) use ($tenant, $operation, $physical, $summary, $receiptDigest, $receiptKey, $wire, $resultDigest, $scope): LateReceiptResult {
                $db = $scope->connection;
                $t = PackageSchema::tables();
                $root = $this->repository->root($tenant, $operation->value, $tx) ?? throw new AdmissionRejected('operation_missing');
                $state = $this->repository->lockSubset($root, $tx);
                $attempt = array_values(array_filter(
                    $state['attempts'],
                    static fn (stdClass $a): bool => RowValues::colStr($a, 'physical_attempt_id') === $physical->value,
                ))[0] ?? throw new AdmissionRejected('authorization_missing');
                $permitsById = [];
                foreach ($state['permits'] as $permitRow) {
                    $permitsById[RowValues::colStr($permitRow, 'id')] = $permitRow;
                }
                $permit = $permitsById[RowValues::colStr($attempt, 'permit_id')];
                $snapshot = RowValues::jsonAssoc(RowValues::colStr($attempt, 'snapshot'));
                $summary = new CooldownService($scope)->attribute($summary, $snapshot, DatabaseClock::now($db));
                if (($summary['status'] ?? null) === 407 && isset($snapshot['endpoint_id'])) {
                    $summary['holds_proxy_credential'] = true;
                }
                if (isset($snapshot['origin'])) {
                    $originStr = RowValues::str($snapshot['origin']);
                    $summary['origin_value'] = $originStr;
                    $summary['origin_scope_key'] = (new SafeUrl($originStr))->origin->budgetScopeKey();
                    foreach (['route_id', 'target_id', 'credential_version', 'policy_revision', 'endpoint_id', 'egress_key', 'profile_id', 'independence_group', 'probe_token', 'probe_epochs', 'purpose', 'cooldown_budget_ids', 'health_interpretation', 'profile_route_set'] as $key) {
                        $summary[$key] = $snapshot[$key] ?? null;
                    }
                    $summary['route_owner_tenant_key'] = $snapshot['route_owner_tenant_key'] ?? $tenant->value;
                    $previousResultSummary = RowValues::colNullableStr($attempt, 'result_summary');
                    if (isset($summary['cooldown_until']) && $previousResultSummary !== null) {
                        $previous = RowValues::jsonAssoc($previousResultSummary);
                        $summary['cooldown_until'] = $previous['cooldown_until'] ?? $summary['cooldown_until'];
                    }
                    $resultDigest = hash('sha256', json_encode($summary, JSON_THROW_ON_ERROR));
                }
                $status = PermitState::from(RowValues::colStr($permit, 'state'));
                if ($wire !== null && ! hash_equals(RowValues::colStr($attempt, 'wire_fingerprint'), $wire->hex)) {
                    return new LateReceiptResult(LateReceiptOutcome::ConflictingReceipt, $status, false, false);
                }
                $existingReceiptKey = RowValues::colNullableStr($attempt, 'receipt_key');
                if ($existingReceiptKey !== null) {
                    $same = hash_equals($existingReceiptKey, $receiptKey)
                        && hash_equals(RowValues::colStr($attempt, 'receipt_digest'), $receiptDigest)
                        && ($wire !== null || hash_equals(RowValues::colStr($attempt, 'result_digest'), $resultDigest));
                    if (! $same) {
                        throw new AdmissionRejected('receipt_conflict');
                    }

                    return new LateReceiptResult(LateReceiptOutcome::IdempotentDuplicate, $status, true, false);
                }
                if (! in_array($status, [PermitState::Authorized, PermitState::UnknownAfterAuthorization], true)) {
                    throw new AdmissionRejected('receipt_invalid_state');
                }
                $permitId = RowValues::colStr($permit, 'id');
                $attemptId = RowValues::colStr($attempt, 'id');
                if (($summary['delivered'] ?? false) !== true) {
                    if ($status === PermitState::Authorized) {
                        $status->transitionTo(PermitState::UnknownAfterAuthorization);
                        $db->table($t['permits'])->where('id', $permitId)->update(['state' => 'unknown_after_authorization']);
                        $db->table($t['attempts'])->where('id', $attemptId)->update(['status' => 'unknown_after_authorization', 'result_summary' => json_encode($summary, JSON_THROW_ON_ERROR)]);
                    }
                    $this->repository->release($permit, $state);
                    $this->observe($tenant, $attemptId, Uuid::random(), $summary, $snapshot, $state['epochs'], 'transport_failure', $tx);

                    return new LateReceiptResult(LateReceiptOutcome::RejectedInvalidState, PermitState::UnknownAfterAuthorization, false, false);
                }
                if ($db->table($t['attempts'])->where('tenant_key', $tenant->value)->where('receipt_key', $receiptKey)->exists()) {
                    throw new AdmissionRejected('receipt_conflict');
                }
                $next = $status->transitionTo(PermitState::Completed);
                $receiptId = Uuid::random();
                $db->table($t['attempts'])->where('id', $attemptId)->whereNull('receipt_key')->update(['status' => 'completed',
                    'receipt_id' => $receiptId->value, 'receipt_key' => $receiptKey, 'receipt_digest' => $receiptDigest, 'result_digest' => $resultDigest,
                    'result_summary' => json_encode($summary, JSON_THROW_ON_ERROR), 'completed_at' => $db->raw('clock_timestamp()')]);
                $db->table($t['permits'])->where('id', $permitId)->update(['state' => $next->value, 'completed_at' => $db->raw('clock_timestamp()')]);
                $db->table($t['demands'])->where('id', RowValues::colStr($permit, 'demand_id'))->where('state', 'admitted')->update(['state' => 'completed']);
                $this->repository->release($permit, $state);
                $snapshot = RowValues::jsonAssoc(RowValues::colStr($attempt, 'snapshot'));
                $epochs = new HealthEpochMap(self::epochMap($snapshot['epochs'] ?? []));
                $live = new HealthEpochMap($state['epochs']);
                $observation = Uuid::random();
                $allowed = ! $epochs->isStaleComparedTo($live);
                $fact = new CompletionFact($tenant, new Uuid($attemptId), $receiptId, $observation, $summary, $epochs, $live);
                if (($summary['purpose'] ?? null) === 'probe') {
                    $allowed = $allowed && new ProbeClaims($scope)->ownsCompletion($fact);
                }
                $db->table($t['observations'])->insert(['id' => $observation->value, 'tenant_key' => $tenant->value, 'attempt_id' => $attemptId,
                    'receipt_id' => $receiptId->value, 'classification' => 'transport_receipt',
                    'evidence' => json_encode(['result' => $summary, 'epochs' => $epochs->epochs, 'reducer_allowed' => $allowed], JSON_THROW_ON_ERROR),
                    'observed_at' => $db->raw('clock_timestamp()')]);
                $this->reducer->apply($fact, $tx);

                return new LateReceiptResult(LateReceiptOutcome::TransitionedToCompleted, $next, true, $allowed);
            });
        } catch (QueryException $error) {
            if ($error->getCode() === '23505' && str_contains($error->getMessage(), 'pxm_receipt_key_unique')) {
                throw new AdmissionRejected('receipt_conflict');
            }
            throw $error;
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, int>  $liveEpochs
     */
    private function observe(TenantKey $tenant, string $attemptId, Uuid $receiptId, array $summary, array $snapshot, array $liveEpochs,
        string $classification, LockedTransactionContext $tx): void
    {
        $db = $this->transactions->scope->connection;
        $t = PackageSchema::tables();
        $previous = $db->table($t['observations'])->where('attempt_id', $attemptId)->where('classification', $classification)->first();
        if ($previous !== null) {
            $previousEvidence = RowValues::jsonAssoc(RowValues::colStr($previous, 'evidence'));
            $previousResult = is_array($previousEvidence['result'] ?? null) ? $previousEvidence['result'] : null;
            if ($previousResult === null || self::canonicalJson($previousResult) !== self::canonicalJson($summary)) {
                throw new AdmissionRejected('receipt_conflict');
            }

            return;
        }
        $epochs = new HealthEpochMap(self::epochMap($snapshot['epochs'] ?? []));
        $live = new HealthEpochMap($liveEpochs);
        $id = Uuid::random();
        $db->table($t['observations'])->insert(['id' => $id->value, 'tenant_key' => $tenant->value, 'attempt_id' => $attemptId,
            'receipt_id' => $receiptId->value, 'classification' => $classification,
            'evidence' => json_encode(['result' => $summary, 'epochs' => $epochs->epochs, 'reducer_allowed' => ! $epochs->isStaleComparedTo($live)], JSON_THROW_ON_ERROR),
            'observed_at' => $db->raw('clock_timestamp()')]);
        $this->reducer->apply(new CompletionFact($tenant, new Uuid($attemptId), $receiptId, $id, $summary, $epochs, $live), $tx);
    }

    /** @return array<string, mixed> */
    private static function summary(AttemptResult $result): array
    {
        $head = $result->head;

        $summary = ['delivered' => $result->wasDelivered(), 'status' => $head?->statusCode, 'failure' => $result->failure?->value,
            'wire_bytes' => $result->wireBytes, 'decoded_bytes' => $result->decodedBytes, 'curl_error' => $result->curlErrorCode,
            'headers_digest' => hash('sha256', json_encode($head?->headers, JSON_THROW_ON_ERROR)),
            'body_hash' => $result->response?->body->contentHash()->hex, 'body_length' => $result->response?->body->byteLength()];
        $summary['_retry_after'] = substr($head?->header('retry-after') ?? '', 0, 512);
        if ($head?->statusCode === 429) {
            $summary['extends_origin_cooldown'] = true;
        }

        return $summary;
    }

    /** @return array<string, int> */
    private static function epochMap(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $epochs = [];
        foreach ($raw as $key => $value) {
            if (is_string($key)) {
                $epochs[$key] = RowValues::int($value);
            }
        }

        return $epochs;
    }

    /** @param array<array-key, mixed> $value */
    private static function canonicalJson(array $value): string
    {
        $sort = static function (array &$data) use (&$sort): void {
            if (! array_is_list($data)) {
                ksort($data);
            }
            foreach ($data as &$item) {
                if (is_array($item)) {
                    $sort($item);
                }
            }
        };
        $sort($value);

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
