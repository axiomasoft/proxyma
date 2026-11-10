<?php

declare(strict_types=1);

namespace Proxyma\Application\Recovery;

use LogicException;
use Proxyma\Application\Admission\TransactionCoordinator;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Database\ReconcileRepository;
use Proxyma\Infrastructure\Eloquent\TemporaryArtifactRecord;

final readonly class OwnedTemporaryArtifacts
{
    public function __construct(private TransactionCoordinator $transactions, private ReconcileRepository $repository,
        private CatalogAccess $access, private string $root) {}

    /** Only this factory creates owned paths; no arbitrary path registration. File I/O precedes SQL. */
    public function create(TenantKey $tenant, Uuid $operation, Uuid $permit, string $bytes): Uuid
    {
        $this->transactions->scope->assertOutsideTransaction();
        $this->access->authorize($tenant, 'execution.artifact.create', $operation);
        $id = Uuid::random();
        $token = bin2hex(random_bytes(32));
        $path = $this->path($id);
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new LogicException('Cannot create owned temporary artifact.');
        }
        try {
            $data = $token."\n".$bytes;
            if (fwrite($handle, $data) !== strlen($data)) {
                throw new LogicException('Incomplete temporary artifact.');
            }
        } catch (\Throwable $error) {
            unlink($path);
            throw $error;
        } finally {
            fclose($handle);
        }
        try {
            $this->transactions->run(function ($tx) use ($id, $tenant, $operation, $permit, $token): void {
                $root = $this->repository->root($tenant, $operation->value, $tx) ?? throw new AdmissionRejected('operation_missing');
                $state = $this->repository->lockSubset($root, $tx);
                if (! in_array($permit->value, array_column($state['permits'], 'id'), true)) {
                    throw new AdmissionRejected('artifact_permit_missing');
                }
                $this->transactions->scope->connection->table((new TemporaryArtifactRecord)->getTable())->insert(['id' => $id->value,
                    'tenant_key' => $tenant->value, 'operation_id' => $operation->value, 'permit_id' => $permit->value, 'ownership_token' => $token]);
            });
        } catch (\Throwable $error) {
            unlink($path);
            throw $error;
        }

        return $id;
    }

    public function prune(TenantKey $tenant, int $retentionSeconds = 2592000, int $limit = 32): int
    {
        $scope = $this->transactions->scope;
        $scope->assertOutsideTransaction();
        if ($retentionSeconds < 1 || $limit < 1 || $limit > 64) {
            throw new \InvalidArgumentException('Positive retention and page 1..64 are required.');
        }
        $this->access->authorize($tenant, 'execution.artifact.prune', new Uuid('00000000-0000-0000-0000-000000000001'));
        $table = (new TemporaryArtifactRecord)->getTable();
        $db = $scope->connection;
        $rows = $db->table($table.' as a')->join(PackageSchema::tables()['permits'].' as p', 'p.id', '=', 'a.permit_id')
            ->where('a.tenant_key', $tenant->value)->whereIn('p.state', ['completed', 'cancelled_before_send', 'expired_before_send'])
            ->whereNotNull('p.lease_released_at')->whereRaw("a.created_at<clock_timestamp()-(?::bigint*interval '1 second')", [$retentionSeconds])
            ->select('a.*')->orderBy('a.id')->limit($limit)->get();
        $count = 0;
        foreach ($rows as $row) {
            $pending = $this->transactions->run(function ($tx) use ($row, $tenant, $db, $table): bool {
                $root = $this->repository->root($tenant, $row->operation_id, $tx, true);
                if ($root === null) {
                    return false;
                }
                $state = $this->repository->lockSubset($root, $tx);
                $permit = array_column($state['permits'], null, 'id')[$row->permit_id];
                if (! in_array($permit->state, ['completed', 'cancelled_before_send', 'expired_before_send'], true) || $permit->lease_released_at === null) {
                    return false;
                }
                $db->table($table)->where('id', $row->id)->update(['delete_pending' => true]);

                return true;
            });
            if (! $pending) {
                continue;
            }
            $path = $this->path(new Uuid($row->id));
            if (is_link($path)) {
                continue;
            }
            if (file_exists($path)) {
                $handle = fopen($path, 'rb');
                if ($handle === false) {
                    continue;
                }
                $header = fgets($handle, 66);
                fclose($handle);
                if ($header !== $row->ownership_token."\n" || ! unlink($path)) {
                    continue;
                }
            }
            $count += $this->transactions->run(function ($tx) use ($row, $tenant, $db, $table): int {
                $root = $this->repository->root($tenant, $row->operation_id, $tx, true);
                if ($root === null) {
                    return 0;
                }

                return $db->table($table)->where('id', $row->id)->where('delete_pending', true)->delete();
            });
        }

        return $count;
    }

    private function path(Uuid $id): string
    {
        if (! str_starts_with($this->root, '/') || in_array('..', explode('/', $this->root), true)) {
            throw new LogicException('Artifact root must be an absolute local path without traversal.');
        }
        $part = '';
        foreach (array_filter(explode('/', $this->root)) as $segment) {
            $part .= '/'.$segment;
            if (is_link($part)) {
                throw new LogicException('Artifact root must not contain symlinks.');
            }
        }
        if (! is_dir($this->root) && ! mkdir($this->root, 0700, true)) {
            throw new LogicException('Cannot create artifact root.');
        }

        return rtrim($this->root, '/').'/.pxm-tmp-'.$id->value.'.part';
    }
}
