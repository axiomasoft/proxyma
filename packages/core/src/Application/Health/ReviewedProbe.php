<?php

declare(strict_types=1);

namespace Proxyma\Application\Health;

use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Catalog\CatalogConflict;
use Proxyma\Application\Maintenance\BoundedProbe;
use Proxyma\Contracts\CatalogQueries;
use Proxyma\Contracts\PolicyResolver;
use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\HealthScope;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Audit\CatalogAudit;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Database\PackageSchema;
use Throwable;

final readonly class ReviewedProbe
{
    public function __construct(private ConnectionScope $scope, private CatalogAccess $access, private CatalogQueries $catalog,
        private PolicyResolver $policies, private BoundedProbe $probe) {}

    public function review(SubjectChain $subjects, Uuid $profileId, Uuid $routeId, Uuid $targetId): array
    {
        $this->scope->assertOutsideTransaction();
        $tenant = $subjects->tenant;
        $profile = $this->catalog->findProfile($tenant, $profileId);
        $route = $this->catalog->findRoute($tenant, $routeId);
        $target = $this->catalog->findTarget($tenant, $targetId);
        if ($profile === null || $route === null || $target === null || ! $route->tenantKey->equals($tenant) || ! $profile->containsRoute($routeId)) {
            throw new CatalogConflict('Probe requires an owned exact profile member and target.');
        }
        $policy = $this->policies->explain($subjects, $targetId)->effectivePolicy ?? throw new CatalogConflict('Policy is unavailable.');
        $versions = ['policy' => $policy->effectiveRevision, 'policy_hash' => $policy->policyHash->hex, 'profile:'.$profileId->value => $profile->revision,
            'route:'.$routeId->value => $route->revision, 'target:'.$targetId->value => $target->revision];
        if ($route->endpointId !== null) {
            $versions['endpoint:'.$route->endpointId->value] = $route->endpointRevision;
        }
        $epochs = [];
        foreach (HealthScope::cases() as $scope) {
            $query = $this->scope->connection->table(PackageSchema::tables()[$scope->value])->where('tenant_key', $tenant->value);
            if ($scope !== HealthScope::Target) {
                $query->where('route_id', $routeId->value);
            }
            if ($scope !== HealthScope::Route) {
                $query->where('target_id', $targetId->value);
            }
            $row = $query->first(['id', 'health_epoch', 'state_version']) ?? throw new CatalogConflict('Health is not materialized.');
            $this->access->authorize($tenant, 'operations.health.read', new Uuid($row->id));
            $key = $scope->value.':'.$row->id;
            $epochs[$key] = (int) $row->health_epoch;
            $versions[$key] = (int) $row->state_version;
        }
        ksort($versions, SORT_STRING);
        ksort($epochs, SORT_STRING);

        return ['versions' => $versions, 'epochs' => $epochs];
    }

    public function execute(SubjectChain $subjects, Uuid $profile, Uuid $route, Uuid $target, array $versions, array $epochs, CatalogMutationActor $actor): array
    {
        $this->scope->assertOutsideTransaction();
        $tenant = $subjects->tenant;
        $this->access->authorize($tenant, 'health.probe.execute', $route, $actor->actor);
        if ($actor->idempotencyKey === null || $actor->expectedRevision === null) {
            throw new CatalogConflict('Probe requires revision and idempotency key.');
        }
        ksort($versions, SORT_STRING);
        ksort($epochs, SORT_STRING);
        $hash = hash('sha256', json_encode(['operator.probe', $subjects, $profile, $route, $target, $versions, $epochs, $actor], JSON_THROW_ON_ERROR));
        if (($receipt = $this->receipt($tenant, $actor, $hash)) !== null) {
            return $receipt;
        }
        if ($this->review($subjects, $profile, $route, $target) !== ['versions' => $versions, 'epochs' => $epochs]) {
            throw new CatalogConflict('Probe review changed.');
        }
        try {
            $this->probe->run($subjects, $profile, $route, $target, review: new ProbeReview($versions, $epochs, $actor, $hash));
        } catch (Throwable $error) {
            // После durable claim повторная отправка запрещена даже при потере ответа.
            return $this->receipt($tenant, $actor, $hash) ?? throw $error;
        }

        return $this->receipt($tenant, $actor, $hash) ?? throw new CatalogConflict('Probe receipt missing.');
    }

    private function receipt(TenantKey $tenant, CatalogMutationActor $actor, string $hash): ?array
    {
        $row = $this->scope->connection->table(PackageSchema::tables()['catalog_command_receipts'])
            ->where('tenant_key', $tenant->value)->where('idempotency_key', CatalogAudit::key($actor->idempotencyKey))->first(['command_hash', 'result']);
        if ($row === null) {
            return null;
        }
        if (! hash_equals($row->command_hash, $hash)) {
            throw new CatalogConflict('Idempotency key was used for another command.');
        }

        return json_decode($row->result, true, flags: JSON_THROW_ON_ERROR);
    }
}
