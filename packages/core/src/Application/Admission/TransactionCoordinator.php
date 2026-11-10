<?php

declare(strict_types=1);

namespace Proxyma\Application\Admission;

use Closure;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use InvalidArgumentException;
use LogicException;
use Proxyma\Application\Execution\WireSealer;
use Proxyma\Contracts\ClaimActivationAuthority;
use Proxyma\Contracts\TransactionCoordinator as CoordinatorContract;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Execution\AuthorizedWireRequest;
use Proxyma\Domain\Execution\AuthorizedWireSnapshot;
use Proxyma\Domain\Execution\ClaimCandidate;
use Proxyma\Domain\Execution\ClaimDenied;
use Proxyma\Domain\Execution\CommitLatch;
use Proxyma\Domain\Execution\ContentHash;
use Proxyma\Domain\Execution\ExecutionBlocked;
use Proxyma\Domain\Execution\ExecutionDeferred;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\PermitWait;
use Proxyma\Domain\Execution\SealedWire;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Database\PackageSchema;
use WeakMap;

final class TransactionCoordinator implements ClaimActivationAuthority, CoordinatorContract
{
    /** @var WeakMap<DatabaseTransactionRecord, CommitLatch> */
    private WeakMap $latches;

    /** @var WeakMap<ClaimCandidate, CommitLatch> */
    private WeakMap $candidates;

    /** @var WeakMap<ClaimCandidate, array{AuthorizedWireSnapshot, Closure, SealedWire}> */
    private WeakMap $authorizations;

    /** @var WeakMap<DatabaseTransactionRecord, array<string, ClaimCandidate>> */
    private WeakMap $claims;

    /** @var Closure(): int */
    private readonly Closure $clock;

    public function __construct(public readonly ConnectionScope $scope, ?Closure $monotonicClock = null)
    {
        $this->latches = new WeakMap;
        $this->candidates = new WeakMap;
        $this->authorizations = new WeakMap;
        $this->claims = new WeakMap;
        $this->clock = $monotonicClock ?? static fn (): int => hrtime(true);
    }

    public function connectionName(): string
    {
        return $this->scope->connection->getName();
    }

    public function transactionLevel(): int
    {
        return $this->scope->connection->transactionLevel();
    }

    public function inTransaction(): bool
    {
        return $this->transactionLevel() > 0;
    }

    public function run(Closure $operation, int $maxAttempts = 3): mixed
    {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('TransactionCoordinator requires at least one attempt.');
        }
        $sample = ($this->clock)();
        if ($this->inTransaction()) {
            return $this->locked($this->scope->connection, $operation, $sample);
        }

        return $this->scope->connection->transaction(fn (): mixed => $this->locked($this->scope->connection, $operation, $sample), $maxAttempts);
    }

    /**
     * @template T
     *
     * @param  Closure(LockedTransactionContext): T  $operation
     * @return T
     */
    public function locked(Connection $caller, Closure $operation, ?int $sampleBeforeJoinNs = null): mixed
    {
        $this->scope->assertSame($caller);
        $root = $this->scope->root();
        if (! isset($this->latches[$root])) {
            $sample = min($sampleBeforeJoinNs ?? PHP_INT_MAX, $this->scope->monotonicBeforeRootNs());
            $latch = new CommitLatch($this->connectionName(), Uuid::random()->value, $sample);
            $this->latches[$root] = $latch;
            // Регистрируем на точном root этой connection, а не на последней глобальной transaction.
            $root->addCallback(function () use ($latch): void {
                $this->scope->assertOutsideTransaction();
                $latch->markOutermostCommitted(0);
            });
            $root->addCallbackForRollback($latch->markRolledBack(...));
        }
        $latch = $this->latches[$root];
        if ($latch->isRolledBack()) {
            throw new AdmissionRejected('transaction_rejected');
        }
        $context = new LockedTransactionContext($this->connectionName(), $latch->outerTransactionToken,
            $latch->monotonicSampledBeforeTxNs, $this->transactionLevel());
        $this->scope->register($context);
        $result = $operation($context);
        $this->scope->assertActive($context);
        if ($result === false || $result instanceof ClaimDenied || $result instanceof ExecutionBlocked
            || $result instanceof ExecutionDeferred || $result instanceof PermitWait) {
            throw new AdmissionRejected($result === false ? 'false_result' : 'denied_result');
        }

        return $result;
    }

    public function issueCandidate(Uuid $permitId, Uuid $operationId, Uuid $physicalAttemptId, ContentHash $wireFingerprint,
        DateTimeImmutable $authorizedAt, DateTimeImmutable $expiresAt, LockedTransactionContext $context): ClaimCandidate
    {
        $this->scope->assertActive($context);
        $latch = $this->latches[$this->scope->root()] ?? throw new LogicException('No active commit latch.');
        if ($latch->outerTransactionToken !== $context->outerTransactionToken) {
            throw new LogicException('Transaction token mismatch.');
        }
        $candidate = $latch->issueCandidate($permitId, $operationId, $physicalAttemptId, $wireFingerprint,
            $authorizedAt, $expiresAt, $this->transactionLevel(), $this);
        $this->candidates[$candidate] = $latch;
        $this->scope->current()->addCallbackForRollback($candidate->invalidateOnRollback(...));

        return $candidate;
    }

    public function activate(ClaimCandidate $candidate, WireRequest $request, AuthorizedWireSnapshot $snapshot, ?int $nowMonotonicNs = null): AuthorizedWireRequest
    {
        $this->scope->assertOutsideTransaction();
        $latch = $this->candidates[$candidate] ?? throw new LogicException('Unknown candidate: foreign coordinator or process restart.');

        $bound = $this->authorizations[$candidate] ?? throw new LogicException('Candidate has no durable sealed authorization.');
        if ($snapshot !== $bound[0] || $nowMonotonicNs !== null) {
            throw new LogicException('Sealed authorization cannot be replaced or supplied a caller clock.');
        }

        return AuthorizedWireRequest::activate($candidate, $latch, 0, $request, $snapshot);
    }

    /** @internal Bound only to a just-written durable authorization, never reconstructed by ID. */
    public function bindAuthorization(ClaimCandidate $candidate, AuthorizedWireSnapshot $snapshot, Closure $onLate,
        SealedWire $wire, WireSealer $sealer): void
    {
        $this->scope->current();
        $sealer->verify($wire);
        $t = PackageSchema::tables();
        $row = $this->scope->connection->table($t['attempts'].' as a')->join($t['permits'].' as p', 'p.id', '=', 'a.permit_id')
            ->where('a.permit_id', $candidate->permitId->value)->where('a.operation_id', $candidate->operationId->value)
            ->where('a.physical_attempt_id', $candidate->physicalAttemptId->value)->where('a.wire_fingerprint', $candidate->wireFingerprint->hex)
            ->where('a.status', 'authorized')->select(['a.*', 'p.expires_at'])->first();
        $createdHere = $this->scope->connection->table($t['attempts'])->where('permit_id', $candidate->permitId->value)
            // Visible, still-in-progress xmin belongs to this root or one of its savepoints.
            ->whereRaw("pg_xact_status(((pg_current_xact_id()::text::bigint / 4294967296) * 4294967296 + xmin::text::bigint)::text::xid8) = 'in progress'")->exists();
        $root = $this->scope->root();
        $key = $candidate->permitId->value;
        if (! isset($this->candidates[$candidate]) || $row === null || ! $createdHere || isset($this->authorizations[$candidate])
            || isset($this->claims[$root][$key])) {
            throw new LogicException('Missing durable authorization or duplicate binding.');
        }
        $fact = json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR);
        if (new DateTimeImmutable($row->authorized_at) != $candidate->authorizedAt || new DateTimeImmutable($row->expires_at) != $candidate->expiresAt
            || ! $snapshot->permitId->equals($candidate->permitId) || $snapshot->routeKind !== $wire->route->kind
            || $snapshot->endpointId?->value !== $wire->route->endpointId?->value || $snapshot->credentialVersion !== $wire->route->credentialVersion
            || $wire->request->fingerprint()->hex !== $candidate->wireFingerprint->hex || $snapshot->fullFingerprint->hex !== $candidate->wireFingerprint->hex
            || $fact['route_id'] !== $snapshot->routeId->value || $fact['endpoint_id'] !== $snapshot->endpointId?->value
            || $fact['credential_version'] !== $snapshot->credentialVersion || $snapshot->routeId->value !== $wire->route->id->value
            || $snapshot->verifiedDestination != $wire->destination || $snapshot->egress != $wire->route->egress) {
            throw new LogicException('Durable sealed authorization snapshot mismatch.');
        }
        $this->claims[$root] = [...($this->claims[$root] ?? []), $key => $candidate];
        $this->scope->current()->addCallbackForRollback(function () use ($root, $key): void {
            $claims = $this->claims[$root] ?? [];
            unset($claims[$key]);
            $this->claims[$root] = $claims;
        });
        $this->authorizations[$candidate] = [$snapshot, $onLate, $wire];
    }

    public function assertActivation(ClaimCandidate $candidate, AuthorizedWireSnapshot $snapshot): void
    {
        $this->scope->assertOutsideTransaction();
        if (! isset($this->authorizations[$candidate]) || $this->authorizations[$candidate][0] !== $snapshot) {
            throw new LogicException('Unknown durable sealed authorization.');
        }
    }

    public function assertOutsideTransaction(): void
    {
        $this->scope->assertOutsideTransaction();
    }

    public function nowMonotonicNs(): int
    {
        return ($this->clock)();
    }

    public function lateRefusal(ClaimCandidate $candidate): void
    {
        ($this->authorizations[$candidate][1])();
    }

    public function preparedWire(ClaimCandidate $candidate): SealedWire
    {
        return $this->authorizations[$candidate][2];
    }
}
