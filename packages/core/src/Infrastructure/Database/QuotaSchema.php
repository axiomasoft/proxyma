<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use LogicException;
use Packman\Attributes\Internal;
use Proxyma\Internal\Database\RowValues;

#[Internal]
final class QuotaSchema
{
    public static function up(Connection $db): void
    {
        $p = PackageSchema::prefix($db);
        $t = PackageSchema::tables($db);
        $db->statement("ALTER TABLE {$t['audit_events']} DROP CONSTRAINT {$p}audit_events_entity_type_check,
            ADD CONSTRAINT {$p}audit_events_entity_type_check CHECK (entity_type IN ('endpoints','routes','route_profiles','targets','shared_route_grants','policy_heads','bindings','budgets'))");
        $db->statement("ALTER TABLE {$t['budgets']} ADD COLUMN hour_limit bigint CHECK (hour_limit >= 0),
            ADD COLUMN day_limit bigint CHECK (day_limit >= 0), ADD COLUMN quota_policy_key text NOT NULL DEFAULT 'default',
            ADD COLUMN traffic_class text, ADD COLUMN traffic_parent_id uuid, ADD COLUMN traffic_percent integer,
            ADD CHECK (quota_policy_key ~ '^[a-z][a-z0-9_.:-]{0,63}$'),
            ADD CHECK ((traffic_class IS NULL AND traffic_parent_id IS NULL AND traffic_percent IS NULL) OR
                (traffic_class IS NOT NULL AND traffic_percent IS NOT NULL AND traffic_class ~ '^[a-z][a-z0-9_.:-]{0,63}$'
                    AND traffic_parent_id IS NOT NULL AND traffic_parent_id <> id AND traffic_percent BETWEEN 0 AND 100)),
            ADD FOREIGN KEY (tenant_key, traffic_parent_id) REFERENCES {$t['budgets']} (tenant_key, id)");
        foreach ($db->select('SELECT conname, pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conrelid=?::regclass', [$t['quota_windows']]) as $constraint) {
            $constraintRow = RowValues::object($constraint);
            $def = RowValues::colStr($constraintRow, 'def');
            if ($def === 'UNIQUE (tenant_key, budget_id, starts_at)' || str_contains($def, 'quota_limit > 0')
                || str_contains($def, 'reserved + spent')) {
                $db->statement('ALTER TABLE '.$t['quota_windows'].' DROP CONSTRAINT '.$db->getQueryGrammar()->wrap(RowValues::colStr($constraintRow, 'conname')));
            }
        }
        $db->statement("ALTER TABLE {$t['quota_windows']} ADD COLUMN window_kind text NOT NULL DEFAULT 'hour',
            ADD COLUMN policy_key text NOT NULL DEFAULT 'default', ADD CHECK (quota_limit >= 0)");
        $db->statement("UPDATE {$t['quota_windows']} SET window_kind='day' WHERE ends_at-starts_at=interval '1 day'");
        $db->statement("ALTER TABLE {$t['quota_windows']} ADD CHECK (window_kind IN ('hour','day')),
            ADD CHECK (policy_key ~ '^[a-z][a-z0-9_.:-]{0,63}$'),
            ADD CHECK (starts_at = (date_trunc(window_kind, starts_at AT TIME ZONE 'UTC') AT TIME ZONE 'UTC')
                AND ends_at = starts_at + CASE window_kind WHEN 'hour' THEN interval '1 hour' ELSE interval '24 hours' END),
            ADD UNIQUE (tenant_key, budget_id, window_kind, starts_at, policy_key)");
        foreach (['hour', 'day'] as $kind) {
            $db->statement("UPDATE {$t['budgets']} b SET {$kind}_limit=(SELECT q.quota_limit FROM {$t['quota_windows']} q
                WHERE q.budget_id=b.id AND q.window_kind=? ORDER BY q.starts_at DESC LIMIT 1)", [$kind]);
        }
        $db->statement("CREATE TABLE {$t['quota_reservations']} (
            id uuid PRIMARY KEY DEFAULT gen_random_uuid(), tenant_key text COLLATE \"C\" NOT NULL,
            permit_id uuid NOT NULL, budget_tenant_key text COLLATE \"C\" NOT NULL, budget_id uuid NOT NULL, window_id uuid NOT NULL,
            cost bigint NOT NULL CHECK (cost > 0), state text NOT NULL CHECK (state IN ('reserved','consumed','released')),
            CHECK (tenant_key ~ '^[a-z0-9][a-z0-9_.:-]{0,127}$'), CHECK (budget_tenant_key=tenant_key OR budget_tenant_key='system'),
            UNIQUE (tenant_key,id), UNIQUE (tenant_key,permit_id,window_id),
            FOREIGN KEY (tenant_key,permit_id) REFERENCES {$t['permits']} (tenant_key,id),
            FOREIGN KEY (budget_tenant_key,window_id,budget_id) REFERENCES {$t['quota_windows']} (tenant_key,id,budget_id))");
        $db->statement("INSERT INTO {$t['quota_reservations']} (tenant_key,permit_id,budget_tenant_key,budget_id,window_id,cost,state)
            SELECT r.tenant_key,r.permit_id,r.budget_tenant_key,r.budget_id,r.quota_window_id,r.cost,
                CASE WHEN p.authorized_at IS NOT NULL OR r.state='spent' THEN 'consumed' ELSE r.state END
                FROM {$t['reservations']} r JOIN {$t['permits']} p ON p.id=r.permit_id WHERE r.quota_window_id IS NOT NULL");
        $db->statement("CREATE FUNCTION {$p}quota_ledger_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
            IF ROW(NEW.id,NEW.tenant_key,NEW.permit_id,NEW.budget_tenant_key,NEW.budget_id,NEW.window_id,NEW.cost)
                IS DISTINCT FROM ROW(OLD.id,OLD.tenant_key,OLD.permit_id,OLD.budget_tenant_key,OLD.budget_id,OLD.window_id,OLD.cost)
                OR (NEW.state<>OLD.state AND NOT (OLD.state='reserved' AND NEW.state IN ('consumed','released'))) THEN
                RAISE EXCEPTION 'immutable quota ledger' USING ERRCODE='23514'; END IF; RETURN NEW; END $$");
        $db->statement("CREATE TRIGGER {$p}quota_ledger BEFORE UPDATE ON {$t['quota_reservations']} FOR EACH ROW EXECUTE FUNCTION {$p}quota_ledger_guard()");
        $db->statement("CREATE FUNCTION {$p}quota_window_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
                IF TG_OP='INSERT' THEN
                    IF NEW.reserved+NEW.spent>NEW.quota_limit THEN RAISE EXCEPTION 'invalid initial quota usage' USING ERRCODE='23514'; END IF;
                ELSIF ROW(NEW.id,NEW.tenant_key,NEW.budget_id,NEW.starts_at,NEW.ends_at,NEW.window_kind,NEW.policy_key)
                    IS DISTINCT FROM ROW(OLD.id,OLD.tenant_key,OLD.budget_id,OLD.starts_at,OLD.ends_at,OLD.window_kind,OLD.policy_key) THEN
                    RAISE EXCEPTION 'immutable quota window' USING ERRCODE='23514'; END IF; RETURN NEW; END $$");
        $db->statement("CREATE TRIGGER {$p}quota_window BEFORE INSERT OR UPDATE ON {$t['quota_windows']} FOR EACH ROW EXECUTE FUNCTION {$p}quota_window_guard()");
        $db->statement("CREATE FUNCTION {$p}quota_policy_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
                IF NEW.quota_policy_key<>OLD.quota_policy_key THEN RAISE EXCEPTION 'immutable quota policy identity' USING ERRCODE='23514'; END IF;
                RETURN NEW; END $$");
        $db->statement("CREATE TRIGGER {$p}quota_policy BEFORE UPDATE ON {$t['budgets']} FOR EACH ROW EXECUTE FUNCTION {$p}quota_policy_guard()");
        $db->statement("ALTER TABLE {$t['demands']} ADD COLUMN traffic_class text NOT NULL DEFAULT 'default' CHECK (traffic_class ~ '^[a-z][a-z0-9_.:-]{0,63}$')");
        $db->statement("CREATE FUNCTION {$p}demand_traffic_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
                IF NEW.traffic_class<>OLD.traffic_class THEN RAISE EXCEPTION 'immutable traffic class' USING ERRCODE='23514'; END IF; RETURN NEW; END $$");
        $db->statement("CREATE TRIGGER {$p}demand_traffic BEFORE UPDATE ON {$t['demands']} FOR EACH ROW EXECUTE FUNCTION {$p}demand_traffic_guard()");
        foreach ($db->table($t['budgets'])->where(fn (Builder $q): Builder => $q->whereNotNull('hour_limit')->orWhereNotNull('day_limit'))
            ->orderByRaw('scope_key COLLATE "C", tenant_key COLLATE "C"')->lockForUpdate()->get() as $budget) {
            QuotaRepository::provisionWindows($db, $budget, DatabaseClock::now($db), reconcileHistory: true);
        }
    }

    public static function down(Connection $db): void
    {
        $p = PackageSchema::prefix($db);
        $t = PackageSchema::tables($db);
        if ($db->table($t['quota_windows'])->exists() || $db->table($t['quota_reservations'])->exists()
            || $db->table($t['budgets'])->whereNotNull('hour_limit')->orWhereNotNull('day_limit')->orWhereNotNull('traffic_class')->exists()
            || $db->table($t['audit_events'])->where('entity_type', 'budgets')->exists()
            || $db->table($t['demands'])->where('traffic_class', '<>', 'default')->exists()) {
            throw new LogicException('Quota history or configuration prevents rollback.');
        }
        $db->statement("ALTER TABLE {$t['audit_events']} DROP CONSTRAINT {$p}audit_events_entity_type_check,
            ADD CONSTRAINT {$p}audit_events_entity_type_check CHECK (entity_type IN ('endpoints','routes','route_profiles','targets','shared_route_grants','policy_heads','bindings'))");
        $db->statement("DROP TABLE {$t['quota_reservations']}");
        $db->statement("DROP FUNCTION {$p}quota_ledger_guard()");
        $db->statement("DROP TRIGGER {$p}quota_window ON {$t['quota_windows']}");
        $db->statement("DROP FUNCTION {$p}quota_window_guard()");
        $db->statement("DROP TRIGGER {$p}quota_policy ON {$t['budgets']}");
        $db->statement("DROP FUNCTION {$p}quota_policy_guard()");
        $db->statement("DROP TRIGGER {$p}demand_traffic ON {$t['demands']}");
        $db->statement("DROP FUNCTION {$p}demand_traffic_guard()");
        $db->statement("ALTER TABLE {$t['demands']} DROP COLUMN traffic_class");
        $db->statement("ALTER TABLE {$t['quota_windows']} DROP COLUMN window_kind, DROP COLUMN policy_key,
            ADD CHECK (quota_limit>0), ADD CHECK (reserved+spent<=quota_limit), ADD UNIQUE (tenant_key,budget_id,starts_at)");
        $db->statement("ALTER TABLE {$t['budgets']} DROP COLUMN hour_limit, DROP COLUMN day_limit, DROP COLUMN quota_policy_key,
            DROP COLUMN traffic_class, DROP COLUMN traffic_parent_id, DROP COLUMN traffic_percent");
    }
}
