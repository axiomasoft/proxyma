<?php

declare(strict_types=1);

namespace Proxyma\Application\Recovery;

use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use Packman\Attributes\Api;
use Proxyma\Application\Admission\TransactionCoordinator;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Domain\Execution\ReconcilePage;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Database\ReconcileRepository;
use Proxyma\Internal\Database\RowValues;

#[Api(since: '1.0')]
final readonly class Reconciler
{
    public function __construct(private TransactionCoordinator $transactions, private ReconcileRepository $repository, private CatalogAccess $access) {}

    public function run(TenantKey $tenant, int $limit = 32): ReconcilePage
    {
        if ($limit < 1 || $limit > 64) {
            throw new InvalidArgumentException('Recovery page must contain 1..64 operation roots.');
        }
        $scope = $this->transactions->scope;
        $scope->assertOutsideTransaction();
        $this->access->authorize($tenant, 'execution.reconcile', new Uuid('00000000-0000-0000-0000-000000000001'));
        $db = $scope->connection;
        $cursorTable = PackageSchema::table('reconcile_cursors', $db);
        $token = Uuid::random()->value;
        $cursor = $db->transaction(function () use ($db, $tenant, $cursorTable, $token): ?object {
            $db->table($cursorTable)->insertOrIgnore(['tenant_key' => $tenant->value]);

            return RowValues::nullableObject($db->selectOne("UPDATE {$cursorTable} SET lease_token=?::uuid,lease_until=clock_timestamp()+interval '60 seconds'
                WHERE tenant_key=? AND (lease_until IS NULL OR lease_until<=clock_timestamp()) RETURNING after_id", [$token, $tenant->value]));
        });
        if ($cursor === null) {
            return new ReconcilePage(0, 0, 0, null, true);
        }
        $t = PackageSchema::tables($db);
        $afterId = RowValues::colNullableStr($cursor, 'after_id');
        $permitsTable = $t['permits'].' as p';
        $demandsTable = $t['demands'].' as d';
        $ids = RowValues::strList(
            $db->table($t['operations'].' as o')->where('o.tenant_key', $tenant->value)
                ->when($afterId !== null, static fn (Builder $q): Builder => $q->where('o.id', '>', $afterId))
                ->where(static function (Builder $w) use ($permitsTable, $demandsTable): void {
                    $w->whereExists(static function (Builder $q) use ($permitsTable): void {
                        $q->selectRaw('1')->from($permitsTable)->whereColumn('p.operation_id', 'o.id')->whereNull('p.lease_released_at')
                            ->where(static function (Builder $p): void {
                                $p->whereRaw('p.expires_at <= clock_timestamp()')->orWhere(static function (Builder $s): void {
                                    $s->where('p.state', 'reserved')->where(static function (Builder $o): void {
                                        $o->where('o.status', '<>', 'active')->orWhereNotNull('o.revoked_at')->orWhereRaw('o.deadline <= clock_timestamp()');
                                    });
                                });
                            });
                    })->orWhereExists(static function (Builder $q) use ($demandsTable): void {
                        $q->selectRaw('1')->from($demandsTable)->whereColumn('d.operation_id', 'o.id')->where('d.state', 'active')
                            ->where(static function (Builder $d): void {
                                $d->whereRaw('least(d.deadline, d.expires_at, o.deadline) <= clock_timestamp()')
                                    ->orWhere('o.status', '<>', 'active')->orWhereNotNull('o.revoked_at');
                            });
                    })->orWhere(static function (Builder $o): void {
                        $o->where('o.status', 'active')->where(static function (Builder $d): void {
                            $d->whereRaw('o.deadline <= clock_timestamp()')->orWhereNotNull('o.revoked_at');
                        });
                    });
                })
                ->orderBy('o.id')->limit($limit)->pluck('o.id')->all(),
        );
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
                    if (RowValues::colStr($root, 'status') === 'active') {
                        $expired = $db->table($t['operations'])->where('id', $id)->whereRaw('deadline<=clock_timestamp() OR revoked_at IS NOT NULL')
                            ->update(['status' => 'expired', 'revision' => $db->raw('revision+1')]);
                        $root = $db->table($t['operations'])->where('id', $id)->first();
                        if ($root === null) {
                            return null;
                        }
                    }

                    return $expired + $this->repository->expire($root, $this->repository->lockSubset($root, $tx));
                });
                $locked += $count === null ? 0 : 1;
                $changed += $count ?? 0;
                $db->table($cursorTable)->where('tenant_key', $tenant->value)->where('lease_token', $token)
                    ->update(['after_id' => $id, 'lease_until' => $db->raw("clock_timestamp()+interval '60 seconds'")]);
            }
            $after = count($ids) < $limit ? null : $ids[count($ids) - 1];
            $db->table($cursorTable)->where('tenant_key', $tenant->value)->where('lease_token', $token)->update(['after_id' => $after]);
        } finally {
            $db->table($cursorTable)->where('tenant_key', $tenant->value)->where('lease_token', $token)->update(['lease_token' => null, 'lease_until' => null]);
        }

        return new ReconcilePage(count($ids), $locked, $changed, $after);
    }
}
