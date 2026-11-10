<?php

declare(strict_types=1);

namespace Proxyma\Application\Recovery;

use Proxyma\Application\Admission\TransactionCoordinator;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Domain\Execution\ReconcilePage;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Database\ReconcileRepository;
use Proxyma\Infrastructure\Eloquent\ReconcileCursorRecord;

final readonly class Reconciler
{
    public function __construct(private TransactionCoordinator $transactions, private ReconcileRepository $repository, private CatalogAccess $access) {}

    public function run(TenantKey $tenant, int $limit = 32): ReconcilePage
    {
        if ($limit < 1 || $limit > 64) {
            throw new \InvalidArgumentException('Recovery page must contain 1..64 operation roots.');
        }
        $scope = $this->transactions->scope;
        $scope->assertOutsideTransaction();
        $this->access->authorize($tenant, 'execution.reconcile', new Uuid('00000000-0000-0000-0000-000000000001'));
        $db = $scope->connection;
        $cursorTable = (new ReconcileCursorRecord)->getTable();
        $token = Uuid::random()->value;
        $cursor = $db->transaction(function () use ($db, $tenant, $cursorTable, $token) {
            $db->table($cursorTable)->insertOrIgnore(['tenant_key' => $tenant->value]);

            return $db->selectOne("UPDATE {$cursorTable} SET lease_token=?::uuid,lease_until=clock_timestamp()+interval '60 seconds'
                WHERE tenant_key=? AND (lease_until IS NULL OR lease_until<=clock_timestamp()) RETURNING after_id", [$token, $tenant->value]);
        });
        if ($cursor === null) {
            return new ReconcilePage(0, 0, 0, null, true);
        }
        $t = PackageSchema::tables();
        $ids = $db->table($t['operations'].' as o')->where('o.tenant_key', $tenant->value)
            ->when($cursor->after_id !== null, fn ($q) => $q->where('o.id', '>', $cursor->after_id))
            ->whereRaw("(EXISTS (SELECT 1 FROM {$t['permits']} p WHERE p.operation_id=o.id AND p.lease_released_at IS NULL AND
                (p.expires_at<=clock_timestamp() OR (p.state='reserved' AND (o.status<>'active' OR o.revoked_at IS NOT NULL OR o.deadline<=clock_timestamp()))))
                OR EXISTS (SELECT 1 FROM {$t['demands']} d WHERE d.operation_id=o.id AND d.state='active' AND
                (least(d.deadline,d.expires_at,o.deadline)<=clock_timestamp() OR o.status<>'active' OR o.revoked_at IS NOT NULL))
                OR (o.status='active' AND (o.deadline<=clock_timestamp() OR o.revoked_at IS NOT NULL)))")
            ->orderBy('o.id')->limit($limit)->pluck('o.id')->all();
        $locked = 0;
        $changed = 0;
        try {
            foreach ($ids as $id) {
                $count = $this->transactions->run(function ($tx) use ($tenant, $id, $db, $t): ?int {
                    $root = $this->repository->root($tenant, $id, $tx, true);
                    if ($root === null) {
                        return null;
                    }
                    $expired = 0;
                    if ($root->status === 'active') {
                        $expired = $db->table($t['operations'])->where('id', $id)->whereRaw('deadline<=clock_timestamp() OR revoked_at IS NOT NULL')
                            ->update(['status' => 'expired', 'revision' => $db->raw('revision+1')]);
                        $root = $db->table($t['operations'])->where('id', $id)->first();
                    }

                    return $expired + $this->repository->expire($root, $this->repository->lockSubset($root, $tx));
                });
                $locked += $count === null ? 0 : 1;
                $changed += $count ?? 0;
                $db->table($cursorTable)->where('tenant_key', $tenant->value)->where('lease_token', $token)
                    ->update(['after_id' => $id, 'lease_until' => $db->raw("clock_timestamp()+interval '60 seconds'")]);
            }
            $after = count($ids) < $limit ? null : end($ids);
            $db->table($cursorTable)->where('tenant_key', $tenant->value)->where('lease_token', $token)->update(['after_id' => $after]);
        } finally {
            $db->table($cursorTable)->where('tenant_key', $tenant->value)->where('lease_token', $token)->update(['lease_token' => null, 'lease_until' => null]);
        }

        return new ReconcilePage(count($ids), $locked, $changed, $after);
    }
}
