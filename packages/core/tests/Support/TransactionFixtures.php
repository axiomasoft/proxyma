<?php

declare(strict_types=1);

namespace Proxyma\Tests\Support;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use Proxyma\Application\Admission\AuthorityLocker;
use Proxyma\Contracts\Admission\LockedAdmissionGuard;
use Proxyma\Contracts\Admission\OwnerAuthority;
use Proxyma\Contracts\Admission\SqlConnectionParticipant;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Admission\AuthorityLockTier;
use Proxyma\Domain\Execution\AdmissionLockTier;
use Proxyma\Domain\Execution\ClaimDenialReason;
use Proxyma\Domain\Execution\ClaimDenied;
use Proxyma\Domain\Execution\LockedOwnerState;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\OwnerRef;
use Proxyma\Domain\Execution\OwnerSnapshot;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Infrastructure\Database\ConnectionScope;

/** Named SQL trace exists only in fixtures; production never logs lock bindings. */
final class NamedLockTrace
{
    public array $names = [];

    public function __construct(Connection $db)
    {
        $db->listen(function ($event): void {
            if (! str_contains(strtolower($event->sql), 'for update')) {
                return;
            }
            if (preg_match('/from "([a-z_]+)"/', $event->sql, $match)) {
                $this->names[] = $match[1];
            }
        });
    }
}

final readonly class ProjectOwnerAuthority implements OwnerAuthority, SqlConnectionParticipant
{
    public function __construct(private ConnectionScope $scope) {}

    public function connection(): Connection
    {
        return $this->scope->connection;
    }

    public function connectionName(): string
    {
        return $this->connection()->getName();
    }

    public function snapshot(OwnerRef $owner): OwnerSnapshot
    {
        $this->scope->assertOutsideTransaction();
        $row = $this->connection()->table('fixture_work')->first();

        return new OwnerSnapshot($owner, new DateTimeImmutable($row->deadline), controlVersions: ['control' => 1], subjectGenerations: ['subject' => 1]);
    }

    public function validateSnapshot(OwnerRef $owner, OwnerSnapshot $snapshot): bool
    {
        return $owner->matchesIdentityAndToken($snapshot->owner);
    }

    public function lockAndValidate(OwnerRef $owner, OwnerSnapshot $snapshot, LockedTransactionContext $transaction): LockedOwnerState
    {
        $this->scope->assertActive($transaction);
        $control = $this->connection()->table('fixture_control')->orderBy('id')->lockForUpdate()->first();
        $subject = $this->connection()->table('fixture_subject')->orderBy('id')->lockForUpdate()->first();
        $work = $this->connection()->table('fixture_work')->orderBy('id')->lockForUpdate()->first();
        $attempt = $this->connection()->table('fixture_attempt')->orderBy('id')->lockForUpdate()->first();
        if (! $this->validateSnapshot($owner, $snapshot) || ! $control->enabled || ! $work->enabled || ! $attempt->enabled
            || (int) $control->revision !== $snapshot->controlVersions['control']
            || (int) $subject->revision !== $snapshot->subjectGenerations['subject']
            || (int) $work->generation !== $owner->generation || ! hash_equals($work->owner_token, $owner->token())) {
            throw new AdmissionRejected('project_owner_lost');
        }

        return new LockedOwnerState($owner, $snapshot->purpose, new DateTimeImmutable($work->deadline), $snapshot->controlVersions);
    }
}

final readonly class FinancialLedger implements SqlConnectionParticipant
{
    public function __construct(private Connection $db, private AuthorityLocker $locks) {}

    public function connection(): Connection
    {
        return $this->db;
    }

    public function reserve(LockedTransactionContext $transaction): void
    {
        $this->locks->participant($this, AuthorityLockTier::Finance, 'account', $transaction);
        $this->db->table('fixture_finance')->where('id', 1)->lockForUpdate()->first();
        $this->db->table('fixture_finance')->where('id', 1)->increment('reserved');
    }
}

final readonly class ProjectAdapterGuard implements LockedAdmissionGuard, SqlConnectionParticipant
{
    public function __construct(private Connection $db, private bool $deny = false, private AdmissionLockTier $declaredTier = AdmissionLockTier::AdapterGuard) {}

    public function connection(): Connection
    {
        return $this->db;
    }

    public function tier(): AdmissionLockTier
    {
        return $this->declaredTier;
    }

    public function evaluateLocked(WireRequest $request, LockedOwnerState $owner, LockedTransactionContext $transaction): ?ClaimDenied
    {
        $this->db->table('fixture_adapter')->where('id', 1)->lockForUpdate()->first();

        return $this->deny ? new ClaimDenied(ClaimDenialReason::GuardDenied) : null;
    }
}
