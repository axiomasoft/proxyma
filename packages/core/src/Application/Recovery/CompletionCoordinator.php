<?php

declare(strict_types=1);

namespace Proxyma\Application\Recovery;

use Illuminate\Database\QueryException;
use Proxyma\Application\Admission\TransactionCoordinator;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Contracts\Completion;
use Proxyma\Contracts\LockedCompletionReducer;
use Proxyma\Contracts\TenantContextResolver;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Execution\AttemptResult;
use Proxyma\Domain\Execution\CompletionFact;
use Proxyma\Domain\Execution\ContentHash;
use Proxyma\Domain\Execution\ExecutionDeferred;
use Proxyma\Domain\Execution\LateReceiptClaim;
use Proxyma\Domain\Execution\LateReceiptOutcome;
use Proxyma\Domain\Execution\LateReceiptResult;
use Proxyma\Domain\Execution\PermitState;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\HealthEpochMap;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Database\ReconcileRepository;

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

    public function recordLateReceipt(LateReceiptClaim $receipt): LateReceiptResult
    {
        $summary = $receipt->verifiedResponse === null ? ['delivered' => true, 'verified_digest_only' => true]
            : self::summary(AttemptResult::delivered($receipt->verifiedResponse));

        return $this->persist($receipt->operationId, $receipt->physicalAttemptId, $summary, $receipt->receiptDigest->hex, $receipt->receiptId, $receipt->wireFingerprint);
    }

    /** All descriptors and hashes are prepared before SQL; body/secret I/O never enters this closure. */
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
                $attempt = array_values(array_filter($state['attempts'], static fn ($a) => $a->physical_attempt_id === $physical->value))[0]
                    ?? throw new AdmissionRejected('authorization_missing');
                $permit = array_column($state['permits'], null, 'id')[$attempt->permit_id];
                $status = PermitState::from($permit->state);
                if ($wire !== null && ! hash_equals($attempt->wire_fingerprint, $wire->hex)) {
                    return new LateReceiptResult(LateReceiptOutcome::ConflictingReceipt, $status, false, false);
                }
                if ($attempt->receipt_key !== null) {
                    $same = hash_equals($attempt->receipt_key, $receiptKey) && hash_equals($attempt->receipt_digest, $receiptDigest)
                        && ($wire !== null || hash_equals($attempt->result_digest, $resultDigest));
                    if (! $same) {
                        throw new AdmissionRejected('receipt_conflict');
                    }

                    return new LateReceiptResult(LateReceiptOutcome::IdempotentDuplicate, $status, true, false);
                }
                if (! in_array($status, [PermitState::Authorized, PermitState::UnknownAfterAuthorization], true)) {
                    throw new AdmissionRejected('receipt_invalid_state');
                }
                if (! $summary['delivered']) {
                    if ($status === PermitState::Authorized) {
                        $status->transitionTo(PermitState::UnknownAfterAuthorization);
                        $status->transitionTo(PermitState::UnknownAfterAuthorization);
                        $db->table($t['permits'])->where('id', $permit->id)->update(['state' => 'unknown_after_authorization']);
                        $db->table($t['attempts'])->where('id', $attempt->id)->update(['status' => 'unknown_after_authorization', 'result_summary' => json_encode($summary, JSON_THROW_ON_ERROR)]);
                    }
                    $this->repository->release($permit, $state);

                    return new LateReceiptResult(LateReceiptOutcome::RejectedInvalidState, PermitState::UnknownAfterAuthorization, false, false);
                }
                if ($db->table($t['attempts'])->where('tenant_key', $tenant->value)->where('receipt_key', $receiptKey)->exists()) {
                    throw new AdmissionRejected('receipt_conflict');
                }
                $next = $status->transitionTo(PermitState::Completed);
                $receiptId = Uuid::random();
                $db->table($t['attempts'])->where('id', $attempt->id)->whereNull('receipt_key')->update(['status' => 'completed',
                    'receipt_id' => $receiptId->value, 'receipt_key' => $receiptKey, 'receipt_digest' => $receiptDigest, 'result_digest' => $resultDigest,
                    'result_summary' => json_encode($summary, JSON_THROW_ON_ERROR), 'completed_at' => $db->raw('clock_timestamp()')]);
                $db->table($t['permits'])->where('id', $permit->id)->update(['state' => $next->value, 'completed_at' => $db->raw('clock_timestamp()')]);
                $db->table($t['demands'])->where('id', $permit->demand_id)->where('state', 'admitted')->update(['state' => 'completed']);
                $this->repository->release($permit, $state);
                $snapshot = json_decode($attempt->snapshot, true, flags: JSON_THROW_ON_ERROR);
                $epochs = new HealthEpochMap($snapshot['epochs'] ?? []);
                $live = new HealthEpochMap($state['epochs']);
                $observation = Uuid::random();
                $allowed = ! $epochs->isStaleComparedTo($live);
                $db->table($t['observations'])->insert(['id' => $observation->value, 'tenant_key' => $tenant->value, 'attempt_id' => $attempt->id,
                    'receipt_id' => $receiptId->value, 'classification' => 'transport_receipt',
                    'evidence' => json_encode(['result' => $summary, 'epochs' => $epochs->epochs, 'reducer_allowed' => $allowed], JSON_THROW_ON_ERROR),
                    'observed_at' => $db->raw('clock_timestamp()')]);
                if ($allowed) {
                    $this->reducer->apply(new CompletionFact($tenant, new Uuid($attempt->id), $receiptId, $observation, $summary, $epochs, $live), $tx);
                }

                return new LateReceiptResult(LateReceiptOutcome::TransitionedToCompleted, $next, true, ! $epochs->isStaleComparedTo($live));
            });
        } catch (QueryException $error) {
            if ($error->getCode() === '23505' && str_contains($error->getMessage(), 'pxm_receipt_key_unique')) {
                throw new AdmissionRejected('receipt_conflict');
            }
            throw $error;
        }
    }

    private static function summary(AttemptResult $result): array
    {
        $head = $result->head;

        return ['delivered' => $result->wasDelivered(), 'status' => $head?->statusCode, 'failure' => $result->failure?->value,
            'wire_bytes' => $result->wireBytes, 'decoded_bytes' => $result->decodedBytes, 'curl_error' => $result->curlErrorCode,
            'headers_digest' => hash('sha256', json_encode($head?->headers, JSON_THROW_ON_ERROR)),
            'body_hash' => $result->response?->body->contentHash()->hex, 'body_length' => $result->response?->body->byteLength()];
    }
}
