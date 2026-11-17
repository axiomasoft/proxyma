<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Admission;

use DateTimeImmutable;
use Packman\Attributes\Api;
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
use Proxyma\Internal\Database\RowValues;

#[Api(since: '1.0')]
final readonly class ReservationCatalogLocks
{
    public function __construct(private ConnectionScope $scope, private AuthorityLocker $locks) {}

    /** @return array{versions: array<string, int>, epochs: array<string, int>} */
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
            $headsFilter = static function (array $state): array {
                $rawVersions = $state['versions'] ?? [];

                return array_filter(is_array($rawVersions) ? $rawVersions : [], static fn (int|string $key): bool => str_starts_with((string) $key, 'head:'), ARRAY_FILTER_USE_KEY);
            };
            if ($headsFilter($input->policyState) !== $headsFilter($livePolicy)
                && array_keys($headsFilter($input->policyState)) === array_keys($headsFilter($livePolicy))) {
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
            $row = RowValues::object($policyHeads[$key."\0".$owner->value] ?? null);
            $headRev = RowValues::colInt($row, 'revision');
            if ($headRev !== $revision) {
                throw new AdmissionRejected('policy_revision_changed');
            }
            $versions['head:'.$owner->value.':'.$key] = $headRev;
        }
        $profile = RowValues::object($this->locks->catalogueRows(AuthorityLockTier::Profile, $tenant, [$input->profile->id->value], $tx)[0] ?? null);
        $profileId = RowValues::colStr($profile, 'id');
        $profileRev = RowValues::colInt($profile, 'revision');
        if (RowValues::col($profile, 'retired_at') !== null || $profileRev !== $input->profile->revision
            || ! $db->table($t['route_profile_members'])->where('tenant_key', $tenant->value)->where('profile_id', $profileId)
                ->where('route_id', $input->route->id->value)->where('route_owner_tenant_key', $input->route->tenantKey->value)->exists()) {
            throw new AdmissionRejected('profile_membership_changed');
        }
        $versions['profile:'.$profileId] = $profileRev;
        if ($input->route->endpointId !== null) {
            $endpoint = RowValues::object($this->locks->catalogueRows(AuthorityLockTier::Endpoint, $input->route->tenantKey, [$input->route->endpointId->value], $tx)[0] ?? null);
            $endpointRev = RowValues::colInt($endpoint, 'revision');
            $endpointProtocol = RowValues::colNullableStr($endpoint, 'protocol');
            if (RowValues::col($endpoint, 'retired_at') !== null || $endpointProtocol === null || ProxyProtocol::tryFrom($endpointProtocol) === null
                || (RowValues::colNullableInt($endpoint, 'credential_version') ?? 1) !== $input->selection->credentialVersion
                || $endpointRev !== $input->route->endpointRevision) {
                throw new AdmissionRejected('endpoint_revision_changed');
            }
            $versions['endpoint:'.RowValues::colStr($endpoint, 'id')] = $endpointRev;
        }
        $route = RowValues::object($this->locks->catalogueRows(AuthorityLockTier::Route, $input->route->tenantKey, [$input->route->id->value], $tx)[0] ?? null);
        $routeId = RowValues::colStr($route, 'id');
        $routeRev = RowValues::colInt($route, 'revision');
        if (! RowValues::colBool($route, 'enabled') || RowValues::col($route, 'retired_at') !== null || $routeRev !== $input->route->revision
            || RowValues::colStr($route, 'egress_key') !== $input->selection->egress->key
            || RowValues::colNullableStr($route, 'endpoint_id') !== $input->selection->endpointId?->value) {
            throw new AdmissionRejected('route_revision_changed');
        }
        $versions['route:'.$routeId] = $routeRev;
        if (! $input->route->tenantKey->equals($tenant)) {
            $grant = $input->route->accessGrant;
            $liveGrant = $grant === null ? null : $db->table($t['shared_route_grants'])->where('id', $grant->id->value)
                ->where('tenant_key', $input->route->tenantKey->value)->where('consumer_tenant_key', $tenant->value)
                ->where('route_id', $routeId)->whereNull('retired_at')->first();
            if ($liveGrant === null || RowValues::colInt($liveGrant, 'revision') !== $grant->revision) {
                throw new AdmissionRejected('shared_grant_changed');
            }
            $versions['grant:'.$grant->id->value] = $grant->revision;
        }
        $epochs = [];
        $routeTenantKey = RowValues::colStr($route, 'tenant_key');
        foreach ([['route_health', AuthorityLockTier::RouteHealth], ['route_target_health', AuthorityLockTier::RouteTargetHealth]] as [$name, $tier]) {
            $query = $db->table($t[$name])->where('tenant_key', $routeTenantKey)->where('route_id', $routeId);
            if ($name === 'route_target_health') {
                $query->where('target_id', $input->target->id->value);
            }
            $id = RowValues::str($query->value('id') ?? throw new AdmissionRejected('route_health_missing'));
            $health = RowValues::object($this->locks->rows($tier, $input->route->tenantKey, [$id], $tx)[0] ?? null);
            if (! $probeClaim) {
                $this->health($health, $input);
            }
            $epochs[$name.':'.$id] = RowValues::colInt($health, 'health_epoch');
            $versions[$name.':'.$id] = RowValues::colInt($health, 'state_version');
        }
        $target = RowValues::object($this->locks->catalogueRows(AuthorityLockTier::Target, $tenant, [$input->target->id->value], $tx)[0] ?? null);
        $targetId = RowValues::colStr($target, 'id');
        $targetRev = RowValues::colInt($target, 'revision');
        $targetOrigin = RowValues::colStr($target, 'canonical_origin');
        $originAllowed = $targetOrigin === $input->request->url->origin->value()
            || ResolvedUrl::isUrlVerifiedForTarget($input->target->id, $input->request->url, $targetRev, (new SafeUrl($targetOrigin))->origin);
        if (RowValues::col($target, 'retired_at') !== null || $targetRev !== $input->target->revision
            || ! $originAllowed) {
            throw new AdmissionRejected('target_revision_changed');
        }
        $versions['target:'.$targetId] = $targetRev;
        $id = RowValues::str($db->table($t['target_health'])->where('tenant_key', $tenant->value)->where('target_id', $targetId)->value('id')
            ?? throw new AdmissionRejected('target_health_missing'));
        $health = RowValues::object($this->locks->rows(AuthorityLockTier::TargetHealth, $tenant, [$id], $tx)[0] ?? null);
        if (! $probeClaim) {
            $this->health($health, $input);
        }
        $healthState = RowValues::colStr($health, 'state');
        $healthAvailability = RowValues::colNullableStr($health, 'availability');
        if (! $probeClaim && $healthState !== 'half_open' && ! in_array($healthAvailability, ['available', 'suspect'], true)) {
            throw new AdmissionRejected('target_health_unavailable');
        }
        $epochs['target_health:'.$id] = RowValues::colInt($health, 'health_epoch');
        $versions['target_health:'.$id] = RowValues::colInt($health, 'state_version');
        if (! $probeClaim) {
            new ProbeClaims($this->scope)->assertAdmission($input, $epochs);
        }

        return ['versions' => $versions, 'epochs' => $epochs];
    }

    private function health(object $row, ReservationInput $input): void
    {
        $openUntil = RowValues::colNullableStr($row, 'open_until');
        if ($openUntil !== null && new DateTimeImmutable($openUntil) > DatabaseClock::now($this->scope->connection)) {
            throw new AdmissionRejected('health_unavailable');
        }
        $state = RowValues::colStr($row, 'state');
        if ($state === 'closed') {
            return;
        }
        $probeUntil = RowValues::colNullableStr($row, 'probe_until');
        if ($state !== 'half_open' || $input->operation->probeToken === null || RowValues::colNullableStr($row, 'probe_token') !== $input->operation->probeToken->value
            || $probeUntil === null || new DateTimeImmutable($probeUntil) <= DatabaseClock::now($this->scope->connection)) {
            throw new AdmissionRejected('health_unavailable');
        }
    }
}
