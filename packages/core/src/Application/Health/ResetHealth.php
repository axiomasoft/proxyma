<?php

declare(strict_types=1);

namespace Proxyma\Application\Health;

use Packman\Attributes\Api;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Catalog\CatalogConflict;
use Proxyma\Application\Catalog\CatalogInvalidMutation;
use Proxyma\Contracts\Catalog\CatalogMutationFence;
use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\HealthScope;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Audit\CatalogAudit;
use Proxyma\Infrastructure\Database\CatalogProvisioner;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Security\StandaloneCatalogMutationFence;
use Proxyma\Internal\Database\RowValues;

#[Api(since: '1.0')]
final readonly class ResetHealth
{
    public function __construct(private ConnectionScope $scope, private CatalogAccess $access) {}

    public function handle(HealthScope $scope, Uuid $id, TenantKey $tenant, CatalogMutationActor $actor): object
    {
        $this->scope->assertOutsideTransaction();
        $ability = 'health.reset';
        $this->access->authorize($tenant, $ability, $id, $actor->actor);
        if ($actor->expectedRevision === null || $actor->idempotencyKey === null) {
            throw new CatalogInvalidMutation('Health reset requires expected revision and idempotency key.');
        }
        $expectedRevision = $actor->expectedRevision;
        $idempotencyKey = $actor->idempotencyKey;
        $hash = hash('sha256', json_encode([$ability, $scope, $id, $tenant, $actor], JSON_THROW_ON_ERROR));
        $db = $this->scope->connection;

        return $db->transaction(function () use ($db, $scope, $id, $tenant, $actor, $expectedRevision, $idempotencyKey, $ability, $hash): object {
            (app()->bound(CatalogMutationFence::class) ? app(CatalogMutationFence::class) : new StandaloneCatalogMutationFence)->lock($db, $actor);
            $t = PackageSchema::tables();
            new CatalogProvisioner($db)->lockCatalog($tenant);
            $receipt = $db->table($t['catalog_command_receipts'])->where('tenant_key', $tenant->value)
                ->where('idempotency_key', CatalogAudit::key($idempotencyKey))->first();
            if ($receipt !== null) {
                if (! hash_equals(RowValues::colStr($receipt, 'command_hash'), $hash)) {
                    throw new CatalogConflict('Idempotency key was used for another command.');
                }

                return RowValues::object(json_decode(RowValues::colStr($receipt, 'result'), false, flags: JSON_THROW_ON_ERROR));
            }
            $query = $db->table($t[$scope->value])->where('tenant_key', $tenant->value)->where('id', $id->value);
            $row = (clone $query)->first();
            if ($row === null || RowValues::colInt($row, 'state_version') !== $expectedRevision) {
                throw new CatalogConflict('Health expected revision does not match.');
            }
            $patch = ['state' => 'unknown', 'health_epoch' => RowValues::colInt($row, 'health_epoch') + 1,
                'state_version' => RowValues::colInt($row, 'state_version') + 1, 'reason_code' => null, 'consecutive_failures' => 0,
                'open_until' => null, 'probe_token' => null, 'probe_until' => null, 'stable_since' => null];
            if ($scope === HealthScope::Route) {
                $patch += ['credential_endpoint_id' => null, 'credential_version' => null];
            }
            if ($scope === HealthScope::Target) {
                $patch['availability'] = 'unknown';
            }
            $query->update($patch);
            $result = (clone $query)->first() ?? throw new CatalogConflict('Health row disappeared.');
            $result->revision = RowValues::colInt($result, 'state_version');
            new CatalogAudit($db)->mutation($scope->value, $tenant, $id, $ability, $actor, $hash, $row, $result, array_keys($patch));

            return CatalogAudit::snapshot($scope->value, $result);
        });
    }
}
