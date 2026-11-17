<?php

declare(strict_types=1);

namespace Proxyma\Application\Admission;

use Closure;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\Events\TransactionRolledBack;
use InvalidArgumentException;
use LogicException;
use Packman\Attributes\Api;
use PDOException;
use Proxyma\Application\Execution\WireSealer;
use Proxyma\Contracts\Admission\ClaimActivationAuthority;
use Proxyma\Contracts\Admission\TransactionCoordinator as CoordinatorContract;
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
use Proxyma\Domain\Execution\ResolvedUrl;
use Proxyma\Domain\Execution\SealedWire;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Internal\Database\RowValues;
use Throwable;
use WeakMap;
use WeakReference;

#[Api(since: '1.0')]
final class TransactionCoordinator implements ClaimActivationAuthority, CoordinatorContract
{
    /** @var WeakMap<DatabaseTransactionRecord, CommitLatch> */
    private WeakMap $latches;

    /** @var WeakMap<ClaimCandidate, CommitLatch> */
    private WeakMap $candidates;

    /** @var WeakMap<ClaimCandidate, array{AuthorizedWireSnapshot, Closure(): void, SealedWire}> */
    private WeakMap $authorizations;

    /** @var WeakMap<DatabaseTransactionRecord, array<string, ClaimCandidate>> */
    private WeakMap $claims;

    /** @var list<Closure():void> */
    private array $rollbackCleanup = [];

    /** @var Closure(): int */
    private readonly Closure $clock;

    /** @param (Closure(): int)|null $monotonicClock */
    public function __construct(public readonly ConnectionScope $scope, ?Closure $monotonicClock = null)
    {
        $this->latches = new WeakMap;
        $this->candidates = new WeakMap;
        $this->authorizations = new WeakMap;
        $this->claims = new WeakMap;
        $this->clock = $monotonicClock ?? static fn (): int => (int) hrtime(true);
        $weak = WeakReference::create($this);
        $scope->connection->getEventDispatcher()?->listen(TransactionRolledBack::class, static function (TransactionRolledBack $event) use ($weak): void {
            $coordinator = $weak->get();
            if ($coordinator !== null && $event->connection === $coordinator->scope->connection && $coordinator->transactionLevel() === 0) {
                $coordinator->flushRollbackCleanup();
            }
        });
    }

    public function connectionName(): string
    {
        return $this->scope->connection->getName() ?? '';
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

        // Один sample ограничивает все retries; SQLSTATE не зависит от языка PG message.
        for ($attempt = 1; ; $attempt++) {
            $root = null;
            try {
                return $this->scope->connection->transaction(function () use ($operation, $sample, &$root): mixed {
                    $root = $this->scope->root();

                    return $this->locked($this->scope->connection, $operation, $sample);
                }, 1);
            } catch (Throwable $exception) {
                $committed = $root !== null && isset($this->latches[$root]) && $this->latches[$root]->isCommitted();
                $pdo = $this->scope->connection->getPdo();
                if ($this->transactionLevel() === 0 && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $this->flushRollbackCleanup();
                $state = $exception instanceof PDOException ? RowValues::str($exception->errorInfo[0] ?? $exception->getCode()) : '';
                if ($committed || $attempt >= $maxAttempts || ! in_array($state, ['40P01', '40001'], true)) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * @internal Cleanup runs after manager teardown and outer rollback, never under caller locks.
     *
     * @param  Closure(): void  $cleanup
     */
    public function afterRootRollback(Closure $cleanup): void
    {
        $this->scope->root()->addCallbackForRollback(function () use ($cleanup): void {
            $this->rollbackCleanup[] = $cleanup;
        });
    }

    public function flushRollbackCleanup(): void
    {
        if ($this->rollbackCleanup === []) {
            return;
        }
        $this->scope->assertOutsideTransaction();
        $pending = $this->rollbackCleanup;
        $this->rollbackCleanup = [];
        foreach ($pending as $cleanup) {
            $cleanup();
        }
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

    /**
     * @internal Bound only to a just-written durable authorization, never reconstructed by ID.
     *
     * @param  Closure(): void  $onLate
     */
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
        $fact = RowValues::jsonAssoc(RowValues::colStr($row, 'snapshot'));
        if ((new DateTimeImmutable(RowValues::colStr($row, 'authorized_at')) <=> $candidate->authorizedAt) !== 0
            || (new DateTimeImmutable(RowValues::colStr($row, 'expires_at')) <=> $candidate->expiresAt) !== 0
            || ! $snapshot->permitId->equals($candidate->permitId) || $snapshot->routeKind !== $wire->route->kind
            || $snapshot->endpointId?->value !== $wire->route->endpointId?->value || $snapshot->credentialVersion !== $wire->route->credentialVersion
            || $wire->request->fingerprint()->hex !== $candidate->wireFingerprint->hex || $snapshot->fullFingerprint->hex !== $candidate->wireFingerprint->hex
            || ($fact['route_id'] ?? null) !== $snapshot->routeId->value || ($fact['endpoint_id'] ?? null) !== $snapshot->endpointId?->value
            || ($fact['credential_version'] ?? null) !== $snapshot->credentialVersion || $snapshot->routeId->value !== $wire->route->id->value
            || ! self::resolvedUrlsEqual($snapshot->verifiedDestination, $wire->destination)
            || ! $snapshot->egress->equals($wire->route->egress)) {
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

    private static function resolvedUrlsEqual(ResolvedUrl $left, ResolvedUrl $right): bool
    {
        return $left->url->equals($right->url)
            && $left->address === $right->address
            && $left->addresses === $right->addresses
            && $left->originalHost === $right->originalHost
            && $left->port === $right->port
            && $left->targetId?->value === $right->targetId?->value
            && $left->targetRevision === $right->targetRevision
            && $left->destinationPolicyKey === $right->destinationPolicyKey
            && ($left->targetOrigin === null
                ? $right->targetOrigin === null
                : ($right->targetOrigin !== null && $left->targetOrigin->equals($right->targetOrigin)));
    }
}
