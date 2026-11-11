<?php

declare(strict_types=1);

namespace Proxyma\Application\Catalog;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Contracts\CatalogCommands;
use Proxyma\Contracts\CatalogMutationFence;
use Proxyma\Contracts\SharedRouteCommands;
use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Catalog\Endpoint;
use Proxyma\Domain\Catalog\RetireCatalogEntityCommand;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Catalog\RouteProfile;
use Proxyma\Domain\Catalog\SetRouteEnabledCommand;
use Proxyma\Domain\Catalog\SharedRouteGrant;
use Proxyma\Domain\Catalog\ShareRouteCommand;
use Proxyma\Domain\Catalog\Target;
use Proxyma\Domain\Catalog\UpsertEndpointCommand;
use Proxyma\Domain\Catalog\UpsertRouteCommand;
use Proxyma\Domain\Catalog\UpsertRouteProfileCommand;
use Proxyma\Domain\Catalog\UpsertTargetCommand;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\BudgetConstraint;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Audit\CatalogAudit;
use Proxyma\Infrastructure\Database\CatalogProvisioner;
use Proxyma\Infrastructure\Database\CatalogRowMapper;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Health\CredentialHolds;
use Proxyma\Infrastructure\Http\TransportDoctor;
use Proxyma\Infrastructure\Security\SharedRouteStorage;
use Proxyma\Infrastructure\Security\StandaloneCatalogMutationFence;

final readonly class DatabaseCatalogCommands implements CatalogCommands, SharedRouteCommands
{
    public function __construct(private Connection $db, private CatalogAccess $access) {}

    public function shareRoute(ShareRouteCommand $command): SharedRouteGrant
    {
        // Конструктор проверяет owner/consumer до начала SQL transaction.
        new SharedRouteGrant($command->grantId, $command->owner, $command->consumer, $command->routeId);
        $row = $this->mutate('catalog.route.share', 'shared_route_grants', $command->grantId, $command->owner, $command->actor, $command,
            function (?object $previous) use ($command): array {
                if ($previous !== null) {
                    throw new CatalogInvalidMutation('Grant identity cannot be reassigned; revoke instead.');
                }
                $this->requireActive('routes', $command->owner, $command->routeId);

                return ['consumer_tenant_key' => $command->consumer->value, 'route_id' => $command->routeId->value];
            });

        return SharedRouteStorage::map($row);
    }

    public function revokeRouteGrant(RetireCatalogEntityCommand $command): SharedRouteGrant
    {
        return SharedRouteStorage::map($this->retire('shared_route_grants', 'catalog.route.revoke-share', $command));
    }

    public function upsertEndpoint(UpsertEndpointCommand $command): Endpoint
    {
        TransportDoctor::assertProtocol($command->protocol);
        $row = $this->mutate('catalog.endpoint.upsert', 'endpoints', $command->endpointId, $command->tenantKey, $command->actor, $command,
            function (?object $previous) use ($command): array {
                $value = new Endpoint($command->endpointId, $command->tenantKey, $command->protocol, $command->host, $command->port, $command->credential);
                if ($previous !== null && $previous->credential_ref !== null && $value->credential === null) {
                    throw new CatalogInvalidMutation('Removing credentials requires a new endpoint identity.');
                }
                if ($previous !== null && $value->credential !== null
                    && ($previous->credential_ref !== $value->credential->reference || (int) $previous->credential_version !== $value->credential->version)
                    && $value->credential->version <= (int) ($previous->credential_version ?? 1)) {
                    throw new CatalogInvalidMutation('Credential rotation must advance its version.');
                }

                return ['protocol' => $value->protocol->value, 'host' => $value->host, 'port' => $value->port,
                    'credential_ref' => $value->credential?->reference, 'credential_version' => $value->credential?->version];
            });

        return CatalogRowMapper::endpoint($row);
    }

    public function retireEndpoint(RetireCatalogEntityCommand $command): Endpoint
    {
        return CatalogRowMapper::endpoint($this->retire('endpoints', 'catalog.endpoint.retire', $command));
    }

    public function upsertRoute(UpsertRouteCommand $command): Route
    {
        $row = $this->mutate('catalog.route.upsert', 'routes', $command->routeId, $command->tenantKey, $command->actor, $command,
            function (?object $previous) use ($command): array {
                $value = new Route($command->routeId, $command->tenantKey, $command->routeKey, $command->kind, $command->endpointId,
                    $command->enabled, $command->egress, $command->independenceGroup, sharedGrant: $command->sharedGrant);
                if ($value->endpointId !== null) {
                    $this->requireActive('endpoints', $command->tenantKey, $value->endpointId);
                }

                return ['route_key' => $value->routeKey, 'kind' => $value->kind->value, 'endpoint_id' => $value->endpointId?->value,
                    'enabled' => $value->enabled, 'egress_key' => $value->egress->key, 'conservative_pool' => $value->egress->conservativePoolFallback,
                    'independence_group' => $value->independenceGroup, 'shared_grant' => $value->sharedGrant];
            }, $command->budgets);

        return CatalogRowMapper::route($row);
    }

    public function setRouteEnabled(SetRouteEnabledCommand $command): Route
    {
        $row = $this->mutate('catalog.route.set-enabled', 'routes', $command->routeId, $command->tenantKey, $command->actor, $command,
            function (?object $previous) use ($command): array {
                if ($previous === null) {
                    throw new CatalogNotFound('Route not found in tenant scope.');
                }
                if ($command->enabled && $previous->endpoint_id !== null) {
                    $this->requireActive('endpoints', $command->tenantKey, new Uuid($previous->endpoint_id));
                }

                return ['enabled' => $command->enabled];
            });

        return CatalogRowMapper::route($row);
    }

    public function upsertProfile(UpsertRouteProfileCommand $command): RouteProfile
    {
        $profile = new RouteProfile($command->profileId, $command->tenantKey, $command->profileKey, $command->memberRouteIds, $command->strategyKey);
        $row = $this->mutate('catalog.profile.upsert', 'route_profiles', $command->profileId, $command->tenantKey, $command->actor, $command,
            function (?object $previous) use ($profile): array {
                return ['profile_key' => $profile->profileKey, 'strategy_key' => $profile->strategyKey];
            }, $command->budgets, $profile->memberRouteIds);

        return CatalogRowMapper::profile($row);
    }

    public function upsertTarget(UpsertTargetCommand $command): Target
    {
        $row = $this->mutate('catalog.target.upsert', 'targets', $command->targetId, $command->tenantKey, $command->actor, $command,
            static function (?object $previous) use ($command): array {
                $value = new Target($command->targetId, $command->tenantKey, $command->targetKey, $command->origin, $command->destinationPolicyKey);

                return ['target_key' => $value->targetKey, 'canonical_origin' => $value->origin->value(), 'destination_policy_key' => $value->destinationPolicyKey];
            }, $command->budgets);

        return CatalogRowMapper::target($row);
    }

    public function retireTarget(RetireCatalogEntityCommand $command): Target
    {
        return CatalogRowMapper::target($this->retire('targets', 'catalog.target.retire', $command));
    }

    private function retire(string $table, string $ability, RetireCatalogEntityCommand $command): object
    {
        return $this->mutate($ability, $table, $command->entityId, $command->tenantKey, $command->actor, $command,
            function (?object $previous): array {
                if ($previous === null) {
                    throw new CatalogNotFound('Catalog entity not found in tenant scope.');
                }

                return ['retired_at' => $this->db->raw('clock_timestamp()')];
            });
    }

    /**
     * @param  Closure(?object): array<string, mixed>  $changes
     * @param  list<BudgetConstraint>  $budgets
     * @param  list<Uuid>|null  $members
     */
    private function mutate(string $ability, string $table, Uuid $id, TenantKey $tenant, CatalogMutationActor $actor, object $command, Closure $changes, array $budgets = [], ?array $members = null): object
    {
        if ($this->db->transactionLevel() !== 0) {
            throw new CatalogInvalidMutation('Catalog commands own their provisioning transaction; call before execution locks.');
        }
        $this->access->authorize($tenant, $ability, $id, $actor->actor);
        if ($actor->expectedRevision === null || $actor->idempotencyKey === null) {
            throw new CatalogInvalidMutation('Catalog writes require expectedRevision (0 for create) and idempotencyKey.');
        }
        $budgets = self::checkedBudgets($budgets);
        $hash = hash('sha256', json_encode([$ability, $command], JSON_THROW_ON_ERROR));

        // Каждая ссылка требует отдельного permission; adapter вызывается до transaction.
        if ($members !== null) {
            foreach ($members as $routeId) {
                $this->access->authorize($tenant, 'catalog.route.select', $routeId, $actor->actor);
            }
        }
        if ($command instanceof UpsertRouteCommand && $command->endpointId !== null) {
            $this->access->authorize($tenant, 'catalog.endpoint.read', $command->endpointId, $actor->actor);
        }
        if ($command instanceof ShareRouteCommand) {
            $this->access->authorize($tenant, 'catalog.route.share', $command->routeId, $actor->actor);
        }

        // Повторяем только SQL; внешние authorizer/secret/network вызовы остаются вне closure.
        try {
            return $this->db->transaction(function () use ($ability, $table, $id, $tenant, $actor, $hash, $changes, $budgets, $members): object {
                (app()->bound(CatalogMutationFence::class) ? app(CatalogMutationFence::class) : new StandaloneCatalogMutationFence)->lock($this->db, $actor);
                $t = PackageSchema::tables();
                $provisioner = new CatalogProvisioner($this->db);
                $provisioner->lockCatalog($tenant);
                $receipt = $this->db->table($t['catalog_command_receipts'])->where('tenant_key', $tenant->value)->where('idempotency_key', CatalogAudit::key($actor->idempotencyKey))->first();
                if ($receipt !== null) {
                    if (! hash_equals($receipt->command_hash, $hash)) {
                        throw new CatalogConflict('Idempotency key was used for another command.');
                    }

                    return json_decode($receipt->result, false, 512, JSON_THROW_ON_ERROR);
                }
                $query = $this->db->table($t[$table])->where('tenant_key', $tenant->value)->where('id', $id->value);
                $previous = (clone $query)->first();
                if ((int) ($previous->revision ?? 0) !== $actor->expectedRevision) {
                    throw new CatalogConflict('Catalog expected revision does not match.');
                }
                if ($previous !== null && $previous->retired_at !== null) {
                    throw new CatalogInvalidMutation('Retired catalog identities cannot be changed or revived.');
                }
                $revision = $actor->expectedRevision + 1;
                $patch = $changes($previous);
                $fields = array_keys(array_filter($patch, static fn ($value, $field): bool => $previous === null || ($previous->{$field} ?? null) != $value, ARRAY_FILTER_USE_BOTH));
                $values = $patch + ['revision' => $revision, 'updated_at' => $this->db->raw('clock_timestamp()')];
                if ($previous === null) {
                    $this->db->table($t[$table])->insert(['id' => $id->value, 'tenant_key' => $tenant->value] + $values);
                } else {
                    $query->update($values);
                }
                if ($table === 'endpoints' && $previous !== null) {
                    (new CredentialHolds($this->db))->rotated($tenant->value, $id->value,
                        (int) ($previous->credential_version ?? 1), (int) ($values['credential_version'] ?? 1));
                }
                if ($members !== null) {
                    $pivot = $this->db->table($t['route_profile_members'])->where('tenant_key', $tenant->value)->where('profile_id', $id->value);
                    $pivot->delete();
                    foreach ($members as $position => $routeId) {
                        $owned = $this->db->table($t['routes'])->where('tenant_key', $tenant->value)->where('id', $routeId->value)->whereNull('retired_at')->first();
                        $grant = $owned === null ? (new SharedRouteStorage($this->db))->forConsumer($tenant, $routeId) : null;
                        $owner = $owned === null ? $grant?->owner : $tenant;
                        if ($owner === null) {
                            throw new CatalogNotFound('Route not found in tenant scope.');
                        }
                        $this->requireActive('routes', $owner, $routeId);
                        $this->db->table($t['route_profile_members'])->insert(['tenant_key' => $tenant->value, 'profile_id' => $id->value,
                            'route_id' => $routeId->value, 'route_owner_tenant_key' => $owner->value, 'shared_grant_id' => $grant?->id->value, 'position' => $position]);
                    }
                    $fields[] = 'member_route_ids';
                }
                $provisioner->materialize($tenant, $budgets);
                $result = (clone $query)->first();
                if ($table === 'route_profiles') {
                    $result->member_route_ids = array_map(static fn (Uuid $routeId): string => $routeId->value, $members ?? []);
                }
                if ($table === 'routes') {
                    $endpoint = $result->endpoint_id === null ? null : $this->db->table($t['endpoints'])
                        ->where('tenant_key', $tenant->value)->where('id', $result->endpoint_id)->first();
                    $result->credential_version = $endpoint->credential_version ?? 1;
                    $result->endpoint_revision = $endpoint === null ? null : (int) $endpoint->revision;
                }
                if ($budgets !== []) {
                    $fields[] = 'budgets';
                }
                (new CatalogAudit($this->db))->mutation($table, $tenant, $id, $ability, $actor, $hash, $previous, $result, $fields);

                return CatalogAudit::snapshot($table, $result);
            }, 3);
        } catch (QueryException) {
            // QueryException включает SQL bindings, поэтому не передаём его как previous.
            throw new CatalogStorageFailure;
        }
    }

    private function requireActive(string $table, TenantKey $tenant, Uuid $id): void
    {
        if (! $this->db->table(PackageSchema::tables()[$table])->where('tenant_key', $tenant->value)->where('id', $id->value)->whereNull('retired_at')->exists()) {
            throw new CatalogNotFound('Catalog reference not found in tenant scope.');
        }
    }

    /**
     * @param  array<mixed>  $budgets
     * @return list<BudgetConstraint>
     */
    private static function checkedBudgets(array $budgets): array
    {
        $checked = [];
        foreach ($budgets as $budget) {
            if (! $budget instanceof BudgetConstraint) {
                throw new CatalogInvalidMutation('Configured budgets must be typed BudgetConstraint values.');
            }
            $checked[] = $budget;
        }

        return $checked;
    }
}
