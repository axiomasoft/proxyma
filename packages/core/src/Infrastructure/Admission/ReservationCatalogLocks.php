<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Admission;

use DateTimeImmutable;
use Proxyma\Application\Admission\AuthorityLocker;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Admission\AuthorityLockTier;
use Proxyma\Domain\Admission\ReservationInput;
use Proxyma\Domain\Catalog\ProxyProtocol;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\ResolvedUrl;
use Proxyma\Domain\Execution\SafeUrl;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Database\DatabaseClock;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Health\ProbeClaims;

final readonly class ReservationCatalogLocks
{
    public function __construct(private ConnectionScope $scope, private AuthorityLocker $locks) {}

    public function lock(ReservationInput $input, LockedTransactionContext $tx, bool $probeClaim = false): array
    {
        $tenant = $input->operation->owner->tenantKey;
        $db = $this->scope->connection;
        $t = PackageSchema::tables();
        $versions = ['policy' => $input->policy->effectiveRevision];
        $owners = array_unique([$tenant->value, TenantKey::SYSTEM]);
        sort($owners, SORT_STRING);
        foreach ($owners as $owner) {
            $db->select('SELECT pg_advisory_xact_lock_shared(hashtextextended(?, 0))', ['proxyma:policy-set:'.$owner]);
        }
        $livePolicy = PolicyAuthoritySnapshot::read($db, $input->policy);
        if ($input->policyState !== $livePolicy) {
            $heads = static fn ($state) => array_filter($state['versions'] ?? [], static fn ($key) => str_starts_with($key, 'head:'), ARRAY_FILTER_USE_KEY);
            if ($heads($input->policyState) !== $heads($livePolicy)
                && array_keys($heads($input->policyState)) === array_keys($heads($livePolicy))) {
                throw new AdmissionRejected('policy_revision_changed');
            }
            throw new AdmissionRejected('policy_or_binding_changed');
        }
        $heads = [];
        foreach ($input->policyState['heads'] as [$owner, $key, $revision]) {
            $heads[$key."\0".$owner] = [new TenantKey($owner), $key, $revision];
        }
        foreach ($input->policy->layerRevisions as $layer => $revision) {
            [$kind, $key] = array_pad(explode(':', $layer, 2), 2, '');
            if (! in_array($kind, ['global_db', 'tenant_db', 'panel_db', 'ordered_entity_ancestry'], true)) {
                continue;
            }
            $owner = $kind === 'global_db' ? TenantKey::system() : $tenant;
            if (isset($heads[$key."\0".$owner->value]) && $heads[$key."\0".$owner->value][2] !== $revision) {
                throw new AdmissionRejected('policy_revision_changed');
            }
            if ($kind !== 'ordered_entity_ancestry') {
                $heads[$key."\0".$owner->value] = [$owner, $key, $revision];
            }
        }
        ksort($heads, SORT_STRING);
        $policyHeads = $this->locks->policyRows(array_map(static fn (array $head): array => ['tenant' => $head[0], 'scopeKey' => $head[1]], array_values($heads)), $tx);
        foreach ($heads as [$owner, $key, $revision]) {
            $row = $policyHeads[$key."\0".$owner->value];
            if ((int) $row->revision !== $revision) {
                throw new AdmissionRejected('policy_revision_changed');
            }
            $versions['head:'.$owner->value.':'.$key] = (int) $row->revision;
        }
        $profile = $this->locks->rows(AuthorityLockTier::Profile, $tenant, [$input->profile->id->value], $tx)[0];
        if ($profile->retired_at !== null || (int) $profile->revision !== $input->profile->revision
            || ! $db->table($t['route_profile_members'])->where('tenant_key', $tenant->value)->where('profile_id', $profile->id)
                ->where('route_id', $input->route->id->value)->where('route_owner_tenant_key', $input->route->tenantKey->value)->exists()) {
            throw new AdmissionRejected('profile_membership_changed');
        }
        $versions['profile:'.$profile->id] = (int) $profile->revision;
        if ($input->route->endpointId !== null) {
            $endpoint = $this->locks->rows(AuthorityLockTier::Endpoint, $input->route->tenantKey, [$input->route->endpointId->value], $tx)[0];
            if ($endpoint->retired_at !== null || ProxyProtocol::tryFrom($endpoint->protocol) === null
                || (int) ($endpoint->credential_version ?? 1) !== $input->selection->credentialVersion
                || (int) $endpoint->revision !== $input->route->endpointRevision) {
                throw new AdmissionRejected('endpoint_revision_changed');
            }
            $versions['endpoint:'.$endpoint->id] = (int) $endpoint->revision;
        }
        $route = $this->locks->rows(AuthorityLockTier::Route, $input->route->tenantKey, [$input->route->id->value], $tx)[0];
        if (! $route->enabled || $route->retired_at !== null || (int) $route->revision !== $input->route->revision
            || $route->egress_key !== $input->selection->egress->key || $route->endpoint_id !== $input->selection->endpointId?->value) {
            throw new AdmissionRejected('route_revision_changed');
        }
        $versions['route:'.$route->id] = (int) $route->revision;
        if (! $input->route->tenantKey->equals($tenant)) {
            $grant = $input->route->accessGrant;
            $liveGrant = $grant === null ? null : $db->table($t['shared_route_grants'])->where('id', $grant->id->value)
                ->where('tenant_key', $input->route->tenantKey->value)->where('consumer_tenant_key', $tenant->value)
                ->where('route_id', $route->id)->whereNull('retired_at')->first();
            if ($liveGrant === null || (int) $liveGrant->revision !== $grant->revision) {
                throw new AdmissionRejected('shared_grant_changed');
            }
            $versions['grant:'.$grant->id->value] = (int) $grant->revision;
        }
        $epochs = [];
        foreach ([['route_health', AuthorityLockTier::RouteHealth], ['route_target_health', AuthorityLockTier::RouteTargetHealth]] as [$name, $tier]) {
            $query = $db->table($t[$name])->where('tenant_key', $route->tenant_key)->where('route_id', $route->id);
            if ($name === 'route_target_health') {
                $query->where('target_id', $input->target->id->value);
            }
            $id = $query->value('id') ?? throw new AdmissionRejected('route_health_missing');
            $health = $this->locks->rows($tier, $input->route->tenantKey, [$id], $tx)[0];
            if (! $probeClaim) {
                $this->health($health, $input);
            }
            $epochs[$name.':'.$id] = (int) $health->health_epoch;
            $versions[$name.':'.$id] = (int) $health->state_version;
        }
        $target = $this->locks->rows(AuthorityLockTier::Target, $tenant, [$input->target->id->value], $tx)[0];
        $originAllowed = $target->canonical_origin === $input->request->url->origin->value()
            || ResolvedUrl::isUrlVerifiedForTarget($input->target->id, $input->request->url, (int) $target->revision, (new SafeUrl($target->canonical_origin))->origin);
        if ($target->retired_at !== null || (int) $target->revision !== $input->target->revision
            || ! $originAllowed) {
            throw new AdmissionRejected('target_revision_changed');
        }
        $versions['target:'.$target->id] = (int) $target->revision;
        $id = $db->table($t['target_health'])->where('tenant_key', $tenant->value)->where('target_id', $target->id)->value('id')
            ?? throw new AdmissionRejected('target_health_missing');
        $health = $this->locks->rows(AuthorityLockTier::TargetHealth, $tenant, [$id], $tx)[0];
        if (! $probeClaim) {
            $this->health($health, $input);
        }
        if (! $probeClaim && $health->state !== 'half_open' && ! in_array($health->availability, ['available', 'suspect'], true)) {
            throw new AdmissionRejected('target_health_unavailable');
        }
        $epochs['target_health:'.$id] = (int) $health->health_epoch;
        $versions['target_health:'.$id] = (int) $health->state_version;
        if (! $probeClaim) {
            (new ProbeClaims($this->scope))->assertAdmission($input, $epochs);
        }

        return ['versions' => $versions, 'epochs' => $epochs];
    }

    private function health(object $row, ReservationInput $input): void
    {
        if ($row->open_until !== null && new DateTimeImmutable($row->open_until) > DatabaseClock::now($this->scope->connection)) {
            throw new AdmissionRejected('health_unavailable');
        }
        if ($row->state === 'closed') {
            return;
        }
        if ($row->state !== 'half_open' || $input->operation->probeToken === null || $row->probe_token !== $input->operation->probeToken->value
            || $row->probe_until === null || new DateTimeImmutable($row->probe_until) <= DatabaseClock::now($this->scope->connection)) {
            throw new AdmissionRejected('health_unavailable');
        }
    }
}
