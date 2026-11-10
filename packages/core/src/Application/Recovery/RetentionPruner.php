<?php

declare(strict_types=1);

namespace Proxyma\Application\Recovery;

use Proxyma\Application\Admission\TransactionCoordinator;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Database\ReconcileRepository;
use Proxyma\Infrastructure\Eloquent\TemporaryArtifactRecord;

final readonly class RetentionPruner
{
    public function __construct(private TransactionCoordinator $transactions, private ReconcileRepository $repository, private CatalogAccess $access) {}

    public function prune(TenantKey $tenant, int $retentionSeconds = 2592000, int $limit = 32): int
    {
        if ($retentionSeconds < 1 || $limit < 1 || $limit > 64) {
            throw new \InvalidArgumentException('Positive retention and page 1..64 are required.');
        }
        $scope = $this->transactions->scope;
        $scope->assertOutsideTransaction();
        $this->access->authorize($tenant, 'execution.retention.prune', new Uuid('00000000-0000-0000-0000-000000000001'));
        $db = $scope->connection;
        $t = PackageSchema::tables();
        $ids = $db->table($t['operations'])->where('tenant_key', $tenant->value)->whereIn('status', ['completed', 'cancelled', 'expired'])
            ->whereRaw("deadline<clock_timestamp()-(?::bigint*interval '1 second')", [$retentionSeconds])
            ->whereExists(fn ($q) => $q->selectRaw('1')->from($t['demands'].' as d')->whereColumn('d.operation_id', $t['operations'].'.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from($t['attempts'].' as a')->whereColumn('a.operation_id', $t['operations'].'.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from($t['permits'].' as p')->whereColumn('p.operation_id', $t['operations'].'.id')
                ->where(fn ($q) => $q->whereNotNull('p.authorized_at')->orWhereNull('p.lease_released_at')))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from((new TemporaryArtifactRecord)->getTable().' as a')->whereColumn('a.operation_id', $t['operations'].'.id'))
            ->orderBy('id')->limit($limit)->pluck('id')->all();
        $removed = 0;
        foreach ($ids as $id) {
            $removed += $this->transactions->run(function ($tx) use ($id, $tenant, $retentionSeconds, $scope, $t): int {
                $db = $scope->connection;
                $root = $this->repository->root($tenant, $id, $tx, true);
                if ($root === null || $root->status === 'active') {
                    return 0;
                }
                $state = $this->repository->lockSubset($root, $tx);
                if ($db->table((new TemporaryArtifactRecord)->getTable())->where('operation_id', $id)->exists()) {
                    return 0;
                }
                // Authorization facts and their associations remain durable; unresolved unknowns hold the whole root.
                if ($state['attempts'] !== [] || array_filter($state['permits'], static fn ($p) => $p->authorized_at !== null || $p->lease_released_at === null) !== []) {
                    return 0;
                }
                $permitIds = array_column($state['permits'], 'id');
                if ($db->table($t['permits'])->whereIn('id', $permitIds)->whereRaw("expires_at>=clock_timestamp()-(?::bigint*interval '1 second')", [$retentionSeconds])->exists()
                    || array_filter($state['demands'], static fn ($d) => ! in_array($d->state, ['expired', 'cancelled', 'completed'], true)) !== []) {
                    return 0;
                }
                $db->table($t['quota_reservations'])->whereIn('permit_id', $permitIds)->where('state', 'released')->delete();
                $db->table($t['reservations'])->whereIn('permit_id', $permitIds)->where('state', 'released')->delete();
                $count = $db->table($t['permits'])->whereIn('id', $permitIds)->delete();
                $demandIds = array_column($state['demands'], 'id');
                $db->table($t['demand_scopes'])->whereIn('demand_id', $demandIds)->delete();

                return $count + $db->table($t['demands'])->whereIn('id', $demandIds)->delete();
            });
        }

        return $removed;
    }
}
