<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Secrets;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Query\JoinClause;
use LogicException;
use Packman\Attributes\Api;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Contracts\Authorization\TenantContextResolver;
use Proxyma\Contracts\Security\SecretResolver;
use Proxyma\Domain\Catalog\CredentialRef;
use Proxyma\Domain\Execution\ResolvedSecret;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Internal\Database\RowValues;
use SensitiveParameter;
use Throwable;

#[Api(since: '1.0')]
final readonly class EncryptedSecretStore implements SecretResolver
{
    public function __construct(private ConnectionScope $scope, private Encrypter $encrypter, private CatalogAccess $access,
        private TenantContextResolver $tenants) {}

    public function encrypt(TenantKey $tenant, #[SensitiveParameter] ResolvedSecret $secret): string
    {
        $this->scope->assertOutsideTransaction();
        try {
            return $this->encrypter->encrypt(json_encode([$tenant->value, $secret->reference->reference,
                $secret->reference->version, $secret->username, $secret->revealSecret()], JSON_THROW_ON_ERROR), false);
        } catch (Throwable) {
            throw new SecretStorageFailure;
        }
    }

    /** @param array<int|string, mixed> $command */
    public function commandHash(#[SensitiveParameter] array $command): string
    {
        $this->scope->assertOutsideTransaction();

        return hash_hmac('sha256', json_encode($command, JSON_THROW_ON_ERROR), $this->encrypter->getKey());
    }

    public function resolve(CredentialRef $credential, TenantKey $tenant): ?ResolvedSecret
    {
        $this->scope->assertOutsideTransaction();
        // Проверяем trusted tenant до lookup, permission конкретного endpoint — до decrypt.
        if (! $this->tenants->resolve()->tenant->equals($tenant)) {
            throw new AuthorizationDenied;
        }
        try {
            $t = PackageSchema::tables();
            $row = $this->scope->connection->table($t['encrypted_secrets'].' as s')
                ->join($t['endpoints'].' as e', function (JoinClause $join): void {
                    $join->on('e.tenant_key', '=', 's.tenant_key')->on('e.id', '=', 's.endpoint_id')
                        ->on('e.credential_ref', '=', 's.reference')->on('e.credential_version', '=', 's.version');
                })->where('s.tenant_key', $tenant->value)->where('s.reference', $credential->reference)
                ->where('s.version', $credential->version)->whereNull('e.retired_at')
                ->select(['s.ciphertext', 's.endpoint_id'])->first();
        } catch (Throwable) {
            throw new SecretStorageFailure;
        }
        if ($row === null) {
            return null;
        }
        $this->access->authorize($tenant, 'catalog.credential.resolve', new Uuid(RowValues::colStr($row, 'endpoint_id')));
        try {
            $decrypted = $this->encrypter->decrypt(RowValues::colStr($row, 'ciphertext'), false);
            if (! is_string($decrypted)) {
                throw new SecretStorageFailure;
            }
            $payload = json_decode($decrypted, true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($payload) || count($payload) !== 5 || array_slice($payload, 0, 3) !== [$tenant->value, $credential->reference, $credential->version]) {
                throw new SecretStorageFailure;
            }

            return new ResolvedSecret($credential, RowValues::nullableStr($payload[3]), RowValues::str($payload[4]));
        } catch (Throwable) {
            throw new SecretStorageFailure;
        }
    }

    public function __debugInfo(): array
    {
        return [];
    }

    public function __serialize(): array
    {
        throw new LogicException('Secret store cannot be serialized.');
    }
}
