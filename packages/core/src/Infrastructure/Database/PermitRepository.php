<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use DateTimeImmutable;
use Proxyma\Application\Admission\AuthorityLocker;
use Proxyma\Domain\Admission\AuthorityLockTier;
use Proxyma\Domain\Execution\ContentHash;
use Proxyma\Domain\Execution\DemandTicket;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\PermitEvidence;
use Proxyma\Domain\Execution\PermitState;
use Proxyma\Domain\Execution\ScheduledPermit;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\HealthEpochMap;
use Proxyma\Domain\Policy\TenantKey;
use stdClass;

final readonly class PermitRepository
{
    public function __construct(private ConnectionScope $scope, private AuthorityLocker $locks) {}

    public function existing(TenantKey $tenant, string $demandId, LockedTransactionContext $tx): ?stdClass
    {
        $row = $this->scope->connection->table(PackageSchema::tables()['permits'])->where('tenant_key', $tenant->value)->where('demand_id', $demandId)->first();

        return $row === null ? null : $this->locks->rows(AuthorityLockTier::Permit, $tenant, [$row->id], $tx)[0];
    }

    public function insert(array $values, array $budgets): stdClass
    {
        $t = PackageSchema::tables();
        $id = Uuid::random()->value;
        $values['id'] = $id;
        $this->scope->connection->table($t['permits'])->insert($values);
        foreach ($budgets as $budget) {
            $this->scope->connection->table($t['reservations'])->insert(['tenant_key' => $values['tenant_key'], 'permit_id' => $id,
                'budget_tenant_key' => $budget->tenant_key, 'budget_id' => $budget->id, 'cost' => $values['cost'], 'state' => 'reserved']);
        }

        return (object) [...$values, 'state' => 'reserved', 'authorized_at' => null];
    }

    public function references(string $id): array
    {
        return $this->scope->connection->table(PackageSchema::tables()['reservations'])->where('permit_id', $id)->orderBy('budget_id')->get()->all();
    }

    public function result(stdClass $row, DemandTicket $ticket): ScheduledPermit
    {
        $evidence = json_decode($row->reservation_evidence, true, flags: JSON_THROW_ON_ERROR);

        return new ScheduledPermit(new Uuid($row->id), $ticket, new DateTimeImmutable($row->not_before), new DateTimeImmutable($row->expires_at),
            new PermitEvidence($evidence['versions'], new HealthEpochMap($evidence['epochs']), new ContentHash($row->wire_fingerprint)),
            PermitState::from($row->state), $row->authorized_at === null ? null : new DateTimeImmutable($row->authorized_at));
    }
}
