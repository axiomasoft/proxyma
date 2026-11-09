<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use Illuminate\Database\Connection;
use Proxyma\Infrastructure\Audit\CatalogCommandReceiptRecord;
use Proxyma\Infrastructure\Eloquent;
use Proxyma\Infrastructure\Security\SharedRouteGrantRecord;
use RuntimeException;

/** @internal PostgreSQL authority; имена берутся из внутренних моделей. */
final class PackageSchema
{
    /** @return array<string, string> */
    public static function tables(): array
    {
        $models = [
            'endpoints' => Eloquent\EndpointRecord::class,
            'routes' => Eloquent\RouteRecord::class,
            'route_profiles' => Eloquent\RouteProfileRecord::class,
            'route_profile_members' => Eloquent\RouteProfileMemberRecord::class,
            'targets' => Eloquent\TargetRecord::class,
            'policy_heads' => Eloquent\PolicyHeadRecord::class,
            'policy_revisions' => Eloquent\PolicyRevisionRecord::class,
            'bindings' => Eloquent\BindingRecord::class,
            'binding_revisions' => Eloquent\BindingRevisionRecord::class,
            'operations' => Eloquent\OperationRecord::class,
            'demands' => Eloquent\DemandRecord::class,
            'permits' => Eloquent\PermitRecord::class,
            'attempts' => Eloquent\AttemptRecord::class,
            'budgets' => Eloquent\BudgetRecord::class,
            'quota_windows' => Eloquent\QuotaWindowRecord::class,
            'reservations' => Eloquent\ReservationRecord::class,
            'route_health' => Eloquent\RouteHealthRecord::class,
            'profile_health' => Eloquent\ProfileHealthRecord::class,
            'route_target_health' => Eloquent\RouteTargetHealthRecord::class,
            'target_health' => Eloquent\TargetHealthRecord::class,
            'observations' => Eloquent\ObservationRecord::class,
            'observation_applications' => Eloquent\ObservationApplicationRecord::class,
            'audit_events' => Eloquent\AuditEventRecord::class,
            'shared_route_grants' => SharedRouteGrantRecord::class,
            'catalog_command_receipts' => CatalogCommandReceiptRecord::class,
        ];

        return array_map(static fn (string $model): string => (new $model)->getTable(), $models);
    }

    public static function up(Connection $db): void
    {
        if ($db->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Proxyma authority requires PostgreSQL.');
        }

        $t = self::tables();
        $key = "text COLLATE \"C\" NOT NULL CHECK (%s ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$')";
        $scope = "text COLLATE \"C\" NOT NULL CHECK (scope_key ~ '^[a-z0-9][a-z0-9_.:-]{0,254}$')";
        $tenant = sprintf($key, 'tenant_key');
        $base = "id uuid PRIMARY KEY DEFAULT gen_random_uuid(), tenant_key {$tenant},
            created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
            updated_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE (tenant_key, id)";
        $revision = 'revision bigint NOT NULL DEFAULT 1 CHECK (revision >= 1)';
        $fk = static fn (string $column, string $parent): string => "FOREIGN KEY (tenant_key, {$column}) REFERENCES {$t[$parent]} (tenant_key, id) ON DELETE RESTRICT";
        $create = static function (string $name, string $columns) use ($db, $t, $base): void {
            $db->statement("CREATE TABLE {$t[$name]} ({$base}, {$columns})");
        };
        $create('endpoints', "protocol text NOT NULL CHECK (protocol IN ('http','https','socks5','socks5h')),
            host text NOT NULL CHECK (length(host) BETWEEN 1 AND 253 AND host = lower(host) AND host !~ '[[:space:]]'),
            port integer NOT NULL CHECK (port BETWEEN 1 AND 65535),
            credential_ref text CHECK (octet_length(credential_ref) BETWEEN 1 AND 255 AND credential_ref !~ '[[:space:]]'),
            credential_version bigint CHECK (credential_version >= 1),
            CHECK ((credential_ref IS NULL) = (credential_version IS NULL)), {$revision}, retired_at timestamptz");
        $create('routes', 'route_key '.sprintf($key, 'route_key').', kind text NOT NULL,
            endpoint_id uuid, enabled boolean NOT NULL DEFAULT true,
            egress_key '.sprintf($key, 'egress_key').",
            conservative_pool boolean NOT NULL DEFAULT false,
            independence_group text CHECK (independence_group ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'),
            shared_grant boolean NOT NULL DEFAULT false, {$revision}, retired_at timestamptz,
            CHECK ((kind = 'proxy' AND endpoint_id IS NOT NULL) OR (kind = 'direct' AND endpoint_id IS NULL)),
            CHECK (egress_key <> route_key AND (independence_group IS NULL OR egress_key <> independence_group)),
            UNIQUE (tenant_key, route_key), ".$fk('endpoint_id', 'endpoints'));
        $create('route_profiles', 'profile_key '.sprintf($key, 'profile_key').",
            strategy_key text NOT NULL CHECK (strategy_key ~ '^[a-z][a-z0-9_.:-]{0,63}$'),
            {$revision}, retired_at timestamptz, UNIQUE (tenant_key, profile_key)");
        $create('route_profile_members', 'profile_id uuid NOT NULL, route_id uuid NOT NULL, position integer NOT NULL CHECK (position >= 0),
            UNIQUE (tenant_key, profile_id, route_id), UNIQUE (tenant_key, profile_id, position), '.$fk('profile_id', 'route_profiles').', '.$fk('route_id', 'routes'));
        $create('targets', 'target_key '.sprintf($key, 'target_key').",
            canonical_origin text NOT NULL CHECK (octet_length(canonical_origin) <= 280 AND canonical_origin = lower(canonical_origin)
                AND canonical_origin ~ '^https?://[^/@[:space:]]+:[1-9][0-9]{0,4}$'
                AND substring(canonical_origin from ':([0-9]+)$')::integer BETWEEN 1 AND 65535),
            destination_policy_key ".sprintf($key, 'destination_policy_key').",
            {$revision}, retired_at timestamptz, UNIQUE (tenant_key, target_key)");
        $create('policy_heads', "scope_key {$scope}, {$revision}, current_revision_id uuid,
            UNIQUE (tenant_key, scope_key)");
        $create('policy_revisions', "head_id uuid NOT NULL, {$revision}, definition jsonb NOT NULL CHECK (jsonb_typeof(definition) = 'object'),
            UNIQUE (tenant_key, head_id, revision), UNIQUE (tenant_key, head_id, id), ".$fk('head_id', 'policy_heads'));
        $db->statement("ALTER TABLE {$t['policy_heads']} ADD CONSTRAINT pxm_policy_current_fk FOREIGN KEY (tenant_key, id, current_revision_id)
            REFERENCES {$t['policy_revisions']} (tenant_key, head_id, id) DEFERRABLE INITIALLY DEFERRED");
        $create('bindings', "subject_type text NOT NULL CHECK (subject_type ~ '^[a-z][a-z0-9_.:-]{0,63}$'),
            subject_id text NOT NULL CHECK (octet_length(subject_id) BETWEEN 1 AND 255),
            head_id uuid NOT NULL, profile_id uuid, {$revision}, retired_at timestamptz,
            UNIQUE (tenant_key, subject_type, subject_id), ".$fk('head_id', 'policy_heads').', '.$fk('profile_id', 'route_profiles'));
        $create('operations', "profile_id uuid NOT NULL, target_id uuid NOT NULL,
            owner_type text NOT NULL CHECK (octet_length(owner_type) BETWEEN 1 AND 64),
            owner_id text NOT NULL CHECK (octet_length(owner_id) BETWEEN 1 AND 255),
            owner_token text NOT NULL CHECK (octet_length(owner_token) BETWEEN 1 AND 255),
            owner_generation bigint NOT NULL CHECK (owner_generation >= 1), {$revision},
            status text NOT NULL DEFAULT 'active' CHECK (status IN ('active','completed','cancelled','expired')),
            deadline timestamptz NOT NULL CHECK (isfinite(deadline)), revoked_at timestamptz,
            connect_timeout_ms integer NOT NULL DEFAULT 1000 CHECK (connect_timeout_ms > 0),
            request_timeout_ms integer NOT NULL DEFAULT 10000 CHECK (request_timeout_ms > 0 AND request_timeout_ms >= connect_timeout_ms),
            max_physical_attempts integer NOT NULL CHECK (max_physical_attempts > 0),
            authorized_attempts integer NOT NULL DEFAULT 0 CHECK (authorized_attempts BETWEEN 0 AND max_physical_attempts),
            UNIQUE (tenant_key, id, profile_id, target_id), ".$fk('profile_id', 'route_profiles').', '.$fk('target_id', 'targets'));
        $create('demands', "operation_id uuid NOT NULL, profile_id uuid NOT NULL, target_id uuid NOT NULL,
            purpose text NOT NULL CHECK (purpose IN ('normal','probe')),
            state text NOT NULL DEFAULT 'active' CHECK (state IN ('active','admitted','completed','cancelled','expired')),
            sequence bigint GENERATED ALWAYS AS IDENTITY, cost bigint NOT NULL CHECK (cost > 0),
            deadline timestamptz NOT NULL CHECK (isfinite(deadline)),
            FOREIGN KEY (tenant_key, operation_id, profile_id, target_id) REFERENCES {$t['operations']} (tenant_key, id, profile_id, target_id),
            UNIQUE (tenant_key, id, operation_id, target_id)");
        $create('permits', "operation_id uuid NOT NULL, demand_id uuid NOT NULL, route_id uuid NOT NULL, target_id uuid NOT NULL,
            physical_attempt_id uuid NOT NULL, wire_fingerprint text NOT NULL CHECK (wire_fingerprint ~ '^[0-9a-f]{64}$'),
            state text NOT NULL DEFAULT 'reserved' CHECK (state IN ('reserved','authorized','completed','unknown_after_authorization','cancelled_before_send','expired_before_send')),
            cost bigint NOT NULL CHECK (cost > 0), not_before timestamptz NOT NULL, expires_at timestamptz NOT NULL,
            authorized_at timestamptz, completed_at timestamptz, claim_id uuid,
            CHECK (isfinite(not_before) AND isfinite(expires_at) AND expires_at > not_before),
            CHECK ((state IN ('reserved','cancelled_before_send','expired_before_send') AND authorized_at IS NULL AND claim_id IS NULL)
                OR (state IN ('authorized','completed','unknown_after_authorization') AND authorized_at IS NOT NULL AND claim_id IS NOT NULL)),
            CHECK ((state = 'completed') = (completed_at IS NOT NULL)),
            UNIQUE (tenant_key, operation_id, physical_attempt_id), UNIQUE (tenant_key, id, operation_id, physical_attempt_id),
            UNIQUE (tenant_key, demand_id), UNIQUE (tenant_key, claim_id),
            FOREIGN KEY (tenant_key, demand_id, operation_id, target_id) REFERENCES {$t['demands']} (tenant_key, id, operation_id, target_id),
            ".$fk('operation_id', 'operations').', '.$fk('route_id', 'routes').', '.$fk('target_id', 'targets'));
        $create('attempts', "operation_id uuid NOT NULL, permit_id uuid NOT NULL, physical_attempt_id uuid NOT NULL,
            receipt_id uuid, wire_fingerprint text NOT NULL CHECK (wire_fingerprint ~ '^[0-9a-f]{64}$'),
            status text NOT NULL CHECK (status IN ('authorized','completed','unknown_after_authorization')),
            authorized_at timestamptz NOT NULL, completed_at timestamptz, snapshot jsonb NOT NULL CHECK (jsonb_typeof(snapshot) = 'object'),
            UNIQUE (tenant_key, operation_id, physical_attempt_id), UNIQUE (tenant_key, receipt_id),
            FOREIGN KEY (tenant_key, permit_id, operation_id, physical_attempt_id) REFERENCES {$t['permits']} (tenant_key, id, operation_id, physical_attempt_id)");
        $create('budgets', "scope_key {$scope}, emission_interval_us bigint NOT NULL CHECK (emission_interval_us > 0),
            burst bigint NOT NULL CHECK (burst > 0), max_inflight bigint NOT NULL CHECK (max_inflight > 0),
            mandatory boolean NOT NULL DEFAULT false, {$revision},
            tat timestamptz NOT NULL DEFAULT '-infinity', send_tat timestamptz NOT NULL DEFAULT '-infinity',
            inflight bigint NOT NULL DEFAULT 0 CHECK (inflight >= 0),
            CONSTRAINT pxm_budget_lock_idx UNIQUE (tenant_key, scope_key)");
        $create('quota_windows', 'budget_id uuid NOT NULL, starts_at timestamptz NOT NULL, ends_at timestamptz NOT NULL,
            CHECK (isfinite(starts_at) AND isfinite(ends_at) AND ends_at > starts_at),
            quota_limit bigint NOT NULL CHECK (quota_limit > 0), reserved bigint NOT NULL DEFAULT 0 CHECK (reserved >= 0),
            spent bigint NOT NULL DEFAULT 0 CHECK (spent >= 0), CHECK (reserved + spent <= quota_limit),
            UNIQUE (tenant_key, budget_id, starts_at), UNIQUE (tenant_key, id, budget_id), '.$fk('budget_id', 'budgets'));
        $create('reservations', "permit_id uuid NOT NULL, budget_id uuid NOT NULL, quota_window_id uuid,
            cost bigint NOT NULL CHECK (cost > 0), state text NOT NULL CHECK (state IN ('reserved','spent','released')),
            UNIQUE (tenant_key, permit_id, budget_id),
            FOREIGN KEY (tenant_key, quota_window_id, budget_id) REFERENCES {$t['quota_windows']} (tenant_key, id, budget_id), ".$fk('permit_id', 'permits').', '.$fk('budget_id', 'budgets'));
        $health = "state text NOT NULL DEFAULT 'unknown' CHECK (state IN ('unknown','closed','open','half_open')),
            health_epoch bigint NOT NULL DEFAULT 1 CHECK (health_epoch >= 1),
            state_version bigint NOT NULL DEFAULT 1 CHECK (state_version >= 1),
            consecutive_failures bigint NOT NULL DEFAULT 0 CHECK (consecutive_failures >= 0),
            recent_openings bigint NOT NULL DEFAULT 0 CHECK (recent_openings >= 0),
            reason_code text, last_success_at timestamptz, last_failure_at timestamptz, open_until timestamptz,
            probe_token uuid, probe_until timestamptz, stable_since timestamptz,
            CHECK ((probe_token IS NULL) = (probe_until IS NULL))";
        foreach (['profile_health' => ['profile_id', 'route_profiles'], 'route_health' => ['route_id', 'routes'], 'target_health' => ['target_id', 'targets']] as $name => [$column, $parent]) {
            $availability = $name === 'target_health' ? ", availability text NOT NULL DEFAULT 'unknown' CHECK (availability IN ('unknown','available','suspect','unavailable'))" : '';
            $create($name, "{$column} uuid NOT NULL, {$health}{$availability}, UNIQUE (tenant_key, {$column}), ".$fk($column, $parent));
        }
        $create('route_target_health', "route_id uuid NOT NULL, target_id uuid NOT NULL, {$health}, UNIQUE (tenant_key, route_id, target_id), ".$fk('route_id', 'routes').', '.$fk('target_id', 'targets'));
        $create('observations', "attempt_id uuid NOT NULL, receipt_id uuid NOT NULL, UNIQUE (tenant_key, receipt_id),
            classification text NOT NULL CHECK (octet_length(classification) BETWEEN 1 AND 64),
            evidence jsonb NOT NULL CHECK (jsonb_typeof(evidence) = 'object'), observed_at timestamptz NOT NULL, ".$fk('attempt_id', 'attempts'));
        $applicationKey = str_replace('scope_key', 'application_key', $scope);
        $create('observation_applications', "observation_id uuid NOT NULL, application_key {$applicationKey}, health_epoch bigint NOT NULL CHECK (health_epoch >= 1),
            UNIQUE (tenant_key, observation_id, application_key), ".$fk('observation_id', 'observations'));
        $create('audit_events', "actor_type text NOT NULL CHECK (actor_type ~ '^[a-z][a-z0-9_.:-]{0,63}$'),
            actor_id text NOT NULL CHECK (octet_length(actor_id) BETWEEN 1 AND 128),
            reason text NOT NULL CHECK (octet_length(reason) BETWEEN 1 AND 512), ability text NOT NULL,
            entity_type text NOT NULL CHECK (entity_type IN ('endpoints','routes','route_profiles','targets')),
            entity_id uuid NOT NULL, previous_revision bigint NOT NULL CHECK (previous_revision >= 0),
            revision bigint NOT NULL CHECK (revision = previous_revision + 1),
            idempotency_key text COLLATE \"C\" NOT NULL CHECK (idempotency_key ~ '^[!-~]{1,128}$'),
            command_hash text NOT NULL CHECK (command_hash ~ '^[0-9a-f]{64}$'),
            result jsonb NOT NULL CHECK (jsonb_typeof(result) = 'object'), UNIQUE (tenant_key, idempotency_key)");

        foreach ([
            'pxm_route_endpoint_idx' => ['routes', '(tenant_key, endpoint_id)'],
            'pxm_member_route_idx' => ['route_profile_members', '(tenant_key, route_id)'],
            'pxm_operation_deadline_idx' => ['operations', "(tenant_key, deadline, id) WHERE status = 'active'"],
            'pxm_demand_fifo_idx' => ['demands', "(tenant_key, profile_id, target_id, sequence) WHERE state = 'active'"],
            'pxm_demand_deadline_idx' => ['demands', "(tenant_key, deadline, id) WHERE state IN ('active','admitted')"],
            'pxm_permit_due_idx' => ['permits', "(tenant_key, expires_at, id) WHERE state IN ('reserved','authorized')"],
            'pxm_permit_operation_idx' => ['permits', '(tenant_key, operation_id, id)'],
            'pxm_attempt_unknown_idx' => ['attempts', "(tenant_key, authorized_at, id) WHERE status = 'unknown_after_authorization'"],
            'pxm_reservation_budget_idx' => ['reservations', '(tenant_key, budget_id, state)'],
            'pxm_route_target_target_idx' => ['route_target_health', '(tenant_key, target_id, route_id)'],
            'pxm_observation_attempt_idx' => ['observations', '(tenant_key, attempt_id)'],
            'pxm_audit_entity_idx' => ['audit_events', '(tenant_key, entity_type, entity_id, revision)'],
        ] as $name => [$table, $columns]) {
            $db->statement("CREATE INDEX {$name} ON {$t[$table]} {$columns}");
        }
        foreach (['route_health', 'profile_health', 'target_health', 'route_target_health'] as $name) {
            $db->statement("CREATE INDEX pxm_{$name}_probe_idx ON {$t[$name]} (tenant_key, open_until, id) WHERE state IN ('open','half_open')");
        }
        $db->unprepared(<<<'SQL'
            CREATE FUNCTION pxm_catalog_revision_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'catalog history requires retirement' USING ERRCODE = '23514';
                END IF;
                IF NEW.id <> OLD.id OR NEW.tenant_key <> OLD.tenant_key OR NEW.revision <> OLD.revision + 1
                    OR (OLD.retired_at IS NOT NULL AND NEW.retired_at IS DISTINCT FROM OLD.retired_at) THEN
                    RAISE EXCEPTION 'catalog identity, revision or retirement violation' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE FUNCTION pxm_append_only_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'immutable authority history' USING ERRCODE = '23514';
            END $$;
            CREATE FUNCTION pxm_permit_transition_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF ROW(NEW.id, NEW.tenant_key, NEW.operation_id, NEW.demand_id, NEW.route_id, NEW.target_id,
                       NEW.physical_attempt_id, NEW.wire_fingerprint, NEW.cost, NEW.not_before, NEW.expires_at)
                    IS DISTINCT FROM
                   ROW(OLD.id, OLD.tenant_key, OLD.operation_id, OLD.demand_id, OLD.route_id, OLD.target_id,
                       OLD.physical_attempt_id, OLD.wire_fingerprint, OLD.cost, OLD.not_before, OLD.expires_at) THEN
                    RAISE EXCEPTION 'permit identity is immutable' USING ERRCODE = '23514';
                END IF;
                IF OLD.claim_id IS NOT NULL AND
                    ROW(NEW.claim_id, NEW.authorized_at) IS DISTINCT FROM ROW(OLD.claim_id, OLD.authorized_at) THEN
                    RAISE EXCEPTION 'permit can be claimed only once' USING ERRCODE = '23514';
                END IF;
                IF NEW.state <> OLD.state AND NOT (
                    (OLD.state = 'reserved' AND NEW.state IN ('authorized','cancelled_before_send','expired_before_send')) OR
                    (OLD.state = 'authorized' AND NEW.state IN ('completed','unknown_after_authorization')) OR
                    (OLD.state = 'unknown_after_authorization' AND NEW.state = 'completed')
                ) THEN
                    RAISE EXCEPTION 'invalid permit lifecycle transition' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            SQL);
        $db->statement("CREATE TRIGGER pxm_permit_transition BEFORE UPDATE ON {$t['permits']} FOR EACH ROW EXECUTE FUNCTION pxm_permit_transition_guard()");
        foreach (['endpoints', 'routes', 'route_profiles', 'targets'] as $name) {
            $db->statement("CREATE TRIGGER pxm_{$name}_revision BEFORE UPDATE OR DELETE ON {$t[$name]} FOR EACH ROW EXECUTE FUNCTION pxm_catalog_revision_guard()");
        }
        foreach (['policy_revisions', 'audit_events'] as $name) {
            $db->statement("CREATE TRIGGER pxm_{$name}_immutable BEFORE UPDATE OR DELETE ON {$t[$name]} FOR EACH ROW EXECUTE FUNCTION pxm_append_only_guard()");
        }
    }

    public static function down(Connection $db): void
    {
        $t = self::tables();
        $db->statement("ALTER TABLE {$t['policy_heads']} DROP CONSTRAINT pxm_policy_current_fk");
        // FK задают порядок удаления; зависимости health/budgets не совпадают с порядком словаря.
        foreach (['audit_events', 'observation_applications', 'observations', 'route_target_health', 'target_health', 'profile_health', 'route_health', 'reservations', 'quota_windows', 'budgets', 'attempts', 'permits', 'demands', 'operations', 'bindings', 'policy_revisions', 'policy_heads', 'targets', 'route_profile_members', 'route_profiles', 'routes', 'endpoints'] as $name) {
            $db->statement("DROP TABLE {$t[$name]}");
        }
        $db->statement('DROP FUNCTION pxm_catalog_revision_guard()');
        $db->statement('DROP FUNCTION pxm_append_only_guard()');
        $db->statement('DROP FUNCTION pxm_permit_transition_guard()');
    }
}
