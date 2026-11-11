<?php

declare(strict_types=1);

namespace Proxyma\Application\Catalog;

use Illuminate\Database\QueryException;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Contracts\CatalogMutationFence;
use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Catalog\CredentialRef;
use Proxyma\Domain\Catalog\Endpoint;
use Proxyma\Domain\Execution\ResolvedSecret;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Audit\CatalogAudit;
use Proxyma\Infrastructure\Database\CatalogProvisioner;
use Proxyma\Infrastructure\Database\CatalogRowMapper;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Health\CredentialHolds;
use Proxyma\Infrastructure\Secrets\EncryptedSecretStore;
use SensitiveParameter;

final readonly class RotateCredentials
{
    public function __construct(private ConnectionScope $scope, private CatalogAccess $access, private EncryptedSecretStore $secrets) {}

    public function handle(Uuid $endpointId, TenantKey $tenant, #[SensitiveParameter] ?string $username,
        #[SensitiveParameter] string $password, CatalogMutationActor $actor): Endpoint
    {
        $this->scope->assertOutsideTransaction();
        $ability = 'catalog.endpoint.rotate-credentials';
        $this->access->authorize($tenant, $ability, $endpointId, $actor->actor);
        if ($actor->expectedRevision === null || $actor->idempotencyKey === null) {
            throw new CatalogInvalidMutation('Credential rotation requires expectedRevision and idempotencyKey.');
        }
        $db = $this->scope->connection;
        $t = PackageSchema::tables();
        $previous = $db->table($t['endpoints'])->where('tenant_key', $tenant->value)->where('id', $endpointId->value)->first();
        if ($previous === null) {
            throw new CatalogNotFound('Endpoint not found in tenant scope.');
        }
        $version = (int) ($previous->credential_version ?? 1);
        if ($version === PHP_INT_MAX) {
            throw new CatalogInvalidMutation('Credential version exhausted.');
        }
        $secret = new ResolvedSecret(new CredentialRef(Uuid::random()->value, $version + 1), $username, $password);
        $hash = $this->secrets->commandHash([$ability, $tenant->value, $endpointId->value, $actor, $username, $password]);
        $ciphertext = $this->secrets->encrypt($tenant, $secret);
        try {
            $row = $db->transaction(function () use ($db, $t, $ability, $tenant, $endpointId, $actor, $secret, $hash, $ciphertext, $version): object {
                app(CatalogMutationFence::class)->lock($db, $actor);
                (new CatalogProvisioner($db))->lockCatalog($tenant);
                $receipt = $db->table($t['catalog_command_receipts'])->where('tenant_key', $tenant->value)
                    ->where('idempotency_key', CatalogAudit::key($actor->idempotencyKey))->first();
                if ($receipt !== null) {
                    if (! hash_equals($receipt->command_hash, $hash)) {
                        throw new CatalogConflict('Idempotency key was used for another command.');
                    }

                    return json_decode($receipt->result, false, 512, JSON_THROW_ON_ERROR);
                }
                $query = $db->table($t['endpoints'])->where('tenant_key', $tenant->value)->where('id', $endpointId->value);
                $previous = (clone $query)->first();
                if ($previous === null || (int) $previous->revision !== $actor->expectedRevision || (int) ($previous->credential_version ?? 1) !== $version) {
                    throw new CatalogConflict('Endpoint changed during credential preparation.');
                }
                if ($previous->retired_at !== null) {
                    throw new CatalogInvalidMutation('Retired endpoint credentials cannot be changed.');
                }
                $db->table($t['encrypted_secrets'])->where('tenant_key', $tenant->value)->where('endpoint_id', $endpointId->value)->delete();
                $db->table($t['encrypted_secrets'])->insert(['tenant_key' => $tenant->value, 'endpoint_id' => $endpointId->value,
                    'reference' => $secret->reference->reference, 'version' => $secret->reference->version, 'ciphertext' => $ciphertext]);
                $query->update(['credential_ref' => $secret->reference->reference, 'credential_version' => $secret->reference->version,
                    'revision' => $actor->expectedRevision + 1, 'updated_at' => $db->raw('clock_timestamp()')]);
                (new CredentialHolds($db))->rotated($tenant->value, $endpointId->value, max(1, $version), $secret->reference->version);
                $db->table($t['routes'])->where('tenant_key', $tenant->value)->where('endpoint_id', $endpointId->value)
                    ->whereNull('retired_at')->update(['revision' => $db->raw('revision + 1'), 'updated_at' => $db->raw('clock_timestamp()')]);
                $result = (clone $query)->first();
                (new CatalogAudit($db))->mutation('endpoints', $tenant, $endpointId, $ability, $actor, $hash, $previous, $result,
                    ['credential_ref', 'credential_version']);

                return $result;
            }, 3);
        } catch (QueryException) {
            throw new CatalogStorageFailure;
        }

        return CatalogRowMapper::endpoint($row);
    }
}
