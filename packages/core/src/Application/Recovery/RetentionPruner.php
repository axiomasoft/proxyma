<?php

declare(strict_types=1);

namespace Proxyma\Application\Recovery;

use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use Packman\Attributes\Api;
use Proxyma\Application\Admission\TransactionCoordinator;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\DatabaseClock;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Database\ReconcileRepository;
use Proxyma\Internal\Database\RowValues;
use stdClass;

#[Api(since: '1.0')]
final readonly class RetentionPruner
{
    public function __construct(private TransactionCoordinator $transactions, private ReconcileRepository $repository, private CatalogAccess $access) {}

    public function prune(TenantKey $tenant, int $retentionSeconds = 2592000, int $limit = 32): int
    {
        if ($retentionSeconds < 1 || $limit < 1 || $limit > 64) {
            throw new InvalidArgumentException('Positive retention and page 1..64 are required.');
        }
        $scope = $this->transactions->scope;
        $scope->assertOutsideTransaction();
        $this->access->authorize($tenant, 'execution.retention.prune', new Uuid('00000000-0000-0000-0000-000000000001'));
        $db = $scope->connection;
        $t = PackageSchema::tables($db);
        $ids = RowValues::strList(
            $db->table($t['operations'])->where('tenant_key', $tenant->value)->whereIn('status', ['completed', 'cancelled', 'expired'])
                ->whereRaw("deadline<clock_timestamp()-(?::bigint*interval '1 second')", [$retentionSeconds])
                ->whereExists(static fn (Builder $q): Builder => $q->selectRaw('1')->from($t['demands'].' as d')->whereColumn('d.operation_id', $t['operations'].'.id'))
                ->whereNotExists(static fn (Builder $q): Builder => $q->selectRaw('1')->from($t['attempts'].' as a')->whereColumn('a.operation_id', $t['operations'].'.id'))
                ->whereNotExists(static fn (Builder $q): Builder => $q->selectRaw('1')->from($t['permits'].' as p')->whereColumn('p.operation_id', $t['operations'].'.id')
                    ->where(static fn (Builder $sub): Builder => $sub->whereNotNull('p.authorized_at')->orWhereNull('p.lease_released_at')))
                ->whereNotExists(static fn (Builder $q): Builder => $q->selectRaw('1')->from($t['permits'].' as p')->whereColumn('p.operation_id', $t['operations'].'.id')
                    ->whereRaw("p.expires_at>=clock_timestamp()-(?::bigint*interval '1 second')", [$retentionSeconds]))
                ->whereNotExists(static fn (Builder $q): Builder => $q->selectRaw('1')->from($t['demands'].' as d')->whereColumn('d.operation_id', $t['operations'].'.id')
                    ->whereNotIn('d.state', ['expired', 'cancelled', 'completed']))
                ->whereNotExists(static fn (Builder $q): Builder => $q->selectRaw('1')->from(PackageSchema::table('temporary_artifacts', $db).' as a')->whereColumn('a.operation_id', $t['operations'].'.id'))
                ->whereNotExists(static fn (Builder $q): Builder => $q->selectRaw('1')->from($t['permits'].' as p')
                    ->join($t['quota_reservations'].' as r', 'r.permit_id', '=', 'p.id')
                    ->join($t['quota_windows'].' as w', 'w.id', '=', 'r.window_id')
                    ->whereColumn('p.operation_id', $t['operations'].'.id')
                    ->whereRaw("w.ends_at>=clock_timestamp()-(?::bigint*interval '1 second')", [$retentionSeconds]))
                ->orderBy('id')->limit($limit)->pluck('id')->all(),
        );
        $removed = 0;
        foreach ($ids as $id) {
            $removed += $this->transactions->run(function ($tx) use ($id, $tenant, $retentionSeconds, $scope, $t): int {
                $db = $scope->connection;
                $root = $this->repository->root($tenant, $id, $tx, true);
                if ($root === null || ! in_array(RowValues::colStr($root, 'status'), ['completed', 'cancelled', 'expired'], true)
                    || new DateTimeImmutable(RowValues::colStr($root, 'deadline')) >= DatabaseClock::now($db)->modify('-'.$retentionSeconds.' seconds')) {
                    return 0;
                }
                $state = $this->repository->lockSubset($root, $tx);
                if (PackageSchema::query($db, 'temporary_artifacts')->where('operation_id', $id)->exists()) {
                    return 0;
                }
                // Authorization facts and their associations remain durable; unresolved unknowns hold the whole root.
                if ($state['attempts'] !== [] || array_filter($state['permits'], static fn (stdClass $p): bool => RowValues::colNullableStr($p, 'authorized_at') !== null || RowValues::colNullableStr($p, 'lease_released_at') === null) !== []) {
                    return 0;
                }
                $permitIds = array_map(static fn (stdClass $p): string => RowValues::colStr($p, 'id'), $state['permits']);
                if ($db->table($t['quota_reservations'].' as r')->join($t['quota_windows'].' as w', 'w.id', '=', 'r.window_id')
                    ->whereIn('r.permit_id', $permitIds)
                    ->whereRaw("w.ends_at>=clock_timestamp()-(?::bigint*interval '1 second')", [$retentionSeconds])->exists()) {
                    return 0;
                }
                if ($db->table($t['permits'])->whereIn('id', $permitIds)->whereRaw("expires_at>=clock_timestamp()-(?::bigint*interval '1 second')", [$retentionSeconds])->exists()
                    || array_filter($state['demands'], static fn (stdClass $d): bool => ! in_array(RowValues::colStr($d, 'state'), ['expired', 'cancelled', 'completed'], true)) !== []) {
                    return 0;
                }
                $db->table($t['quota_reservations'])->whereIn('permit_id', $permitIds)->where('state', 'released')->delete();
                $db->table($t['reservations'])->whereIn('permit_id', $permitIds)->where('state', 'released')->delete();
                $count = $db->table($t['permits'])->whereIn('id', $permitIds)->delete();
                $demandIds = array_map(static fn (stdClass $d): string => RowValues::colStr($d, 'id'), $state['demands']);
                $db->table($t['demand_scopes'])->whereIn('demand_id', $demandIds)->delete();

                return $count + $db->table($t['demands'])->whereIn('id', $demandIds)->delete();
            });
        }

        return $removed;
    }
}
