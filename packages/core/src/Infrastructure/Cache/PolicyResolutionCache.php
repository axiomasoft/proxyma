<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Cache;

use Packman\Attributes\Api;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\EffectivePolicy;
use Proxyma\Domain\Policy\PolicyScope;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\TenantKey;

/**
 * Кэш вычисленных EffectivePolicy в памяти процесса (queue worker / HTTP runtime).
 *
 * Ключ кэша строится исключительно из иммутабельных входов:
 * (tenant, panel, slot, target, ordered_subjects, policy_versions, registry_fingerprint)
 * плюс поколения инвалидации (global + tenant).
 *
 * Кэш никогда не принимает решение о праве на отправку (send eligibility)
 * и не принимает записи внутри открытой БД-транзакции до её коммита.
 */
#[Api(since: '1.0')]
final class PolicyResolutionCache
{
    /** @var array<string, array{tenant: string, policy: EffectivePolicy}> */
    private array $entries = [];

    /** @var array<string, int> */
    private array $tenantGenerations = [];

    private int $globalGeneration = 1;

    private int $hits = 0;

    private int $misses = 0;

    private int $invalidations = 0;

    public function __construct(
        private readonly int $maxEntries = 512,
    ) {}

    /**
     * @param  array<string, int>  $policyVersions
     * @return array{
     *     schema: string,
     *     global_generation: int,
     *     tenant_generation: int,
     *     tenant: string,
     *     panel: string,
     *     slot: string,
     *     target_id: string,
     *     target_revision: int,
     *     ordered_subjects: list<string>,
     *     policy_versions: array<string, int>,
     *     registry_fingerprint: string
     * }
     */
    public function keyComponents(
        TenantKey $tenant,
        string $panelKey,
        string $trafficKey,
        Uuid $targetId,
        int $targetRevision,
        SubjectChain $subjects,
        array $policyVersions,
        string $registryFingerprint,
    ): array {
        ksort($policyVersions, SORT_STRING);

        $orderedSubjects = [];

        foreach ($subjects->ancestors() as $ancestor) {
            $orderedSubjects[] = $ancestor->key();
        }

        $orderedSubjects[] = $subjects->leaf->key();

        $tenantKey = $tenant->value;

        return [
            'schema' => 'pxm.policy.cache.v1',
            'global_generation' => $this->globalGeneration,
            'tenant_generation' => $this->tenantGenerations[$tenantKey] ?? 1,
            'tenant' => $tenantKey,
            'panel' => $panelKey,
            'slot' => $trafficKey,
            'target_id' => $targetId->value,
            'target_revision' => $targetRevision,
            'ordered_subjects' => $orderedSubjects,
            'policy_versions' => $policyVersions,
            'registry_fingerprint' => $registryFingerprint,
        ];
    }

    /**
     * @param  array<string, int>  $policyVersions
     */
    public function buildKey(
        TenantKey $tenant,
        string $panelKey,
        string $trafficKey,
        Uuid $targetId,
        int $targetRevision,
        SubjectChain $subjects,
        array $policyVersions,
        string $registryFingerprint,
    ): string {
        $components = $this->keyComponents(
            tenant: $tenant,
            panelKey: $panelKey,
            trafficKey: $trafficKey,
            targetId: $targetId,
            targetRevision: $targetRevision,
            subjects: $subjects,
            policyVersions: $policyVersions,
            registryFingerprint: $registryFingerprint,
        );

        return 'pxm_policy_'.hash(
            'sha256',
            json_encode($components, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    public function get(string $key): ?EffectivePolicy
    {
        if (! isset($this->entries[$key])) {
            $this->misses++;

            return null;
        }

        $entry = $this->entries[$key];
        unset($this->entries[$key]);
        $this->entries[$key] = $entry;
        $this->hits++;

        return $entry['policy'];
    }

    public function put(
        string $key,
        EffectivePolicy $policy,
        TenantKey $tenant,
        int $transactionLevel = 0,
    ): void {
        // Не записываем состояния из незакоммиченной транзакции в общий кэш воркера.
        if ($transactionLevel > 0) {
            return;
        }

        if ($this->maxEntries <= 0) {
            return;
        }

        if (isset($this->entries[$key])) {
            unset($this->entries[$key]);
        } elseif (count($this->entries) >= $this->maxEntries) {
            $oldestKey = array_key_first($this->entries);
            unset($this->entries[$oldestKey]);
        }

        $this->entries[$key] = [
            'tenant' => $tenant->value,
            'policy' => $policy,
        ];
    }

    public function invalidateScope(TenantKey $tenant, PolicyScope $scope, string $scopeId): void
    {
        if ($scope === PolicyScope::Global || $tenant->isSystem()) {
            $this->invalidateGlobal();

            return;
        }

        $this->invalidateTenant($tenant);
    }

    public function invalidateTenant(TenantKey $tenant): void
    {
        $tenantKey = $tenant->value;
        $this->tenantGenerations[$tenantKey] = ($this->tenantGenerations[$tenantKey] ?? 1) + 1;
        $this->invalidations++;

        foreach ($this->entries as $key => $entry) {
            if ($entry['tenant'] === $tenantKey) {
                unset($this->entries[$key]);
            }
        }
    }

    public function invalidateGlobal(): void
    {
        $this->globalGeneration++;
        $this->invalidations++;
        $this->entries = [];
    }

    public function flush(): void
    {
        $this->globalGeneration++;
        $this->tenantGenerations = [];
        $this->entries = [];
        $this->hits = 0;
        $this->misses = 0;
        $this->invalidations = 0;
    }

    public function hits(): int
    {
        return $this->hits;
    }

    public function misses(): int
    {
        return $this->misses;
    }

    public function invalidations(): int
    {
        return $this->invalidations;
    }

    public function size(): int
    {
        return count($this->entries);
    }
}
