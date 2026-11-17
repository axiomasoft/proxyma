<?php

declare(strict_types=1);

namespace Proxyma\Tests\Support;

require_once __DIR__.'/DestinationFixtures.php';

use Proxyma\Application\Admission\ClaimSend;
use Proxyma\Application\Admission\DemandQueue;
use Proxyma\Application\Admission\OperationCommands;
use Proxyma\Application\Admission\ReservationService;
use Proxyma\Contracts\Admission\OwnerAuthority;
use Proxyma\Domain\Catalog\CredentialRef;
use Proxyma\Domain\Execution\DemandPurpose;
use Proxyma\Domain\Execution\ResolvedSecret;
use Proxyma\Domain\Execution\ScheduledPermit;
use Proxyma\Domain\Execution\TlsTrustConfiguration;
use Proxyma\Infrastructure\Database\ConnectionScope;

trait AuthorityFixtures
{
    protected function initializeAuthorityFixture(): void
    {
        $this->initializeRecoveryFixture();
        foreach (['control', 'subject', 'work', 'attempt'] as $name) {
            $this->db->statement("CREATE TABLE fixture_{$name} (id int PRIMARY KEY, enabled boolean NOT NULL DEFAULT true,
                revision bigint NOT NULL DEFAULT 1,generation bigint NOT NULL DEFAULT 1,owner_token text NOT NULL DEFAULT 'fixture-token',
                deadline timestamptz NOT NULL DEFAULT (clock_timestamp()+interval '1 hour'))");
            $this->db->table('fixture_'.$name)->insert(['id' => 1]);
        }
        $this->db->statement('CREATE TABLE fixture_finance (id int PRIMARY KEY,reserved int NOT NULL DEFAULT 0 CHECK(reserved>=0))');
        $this->db->table('fixture_finance')->insert([['id' => 1], ['id' => 2]]);
        $this->db->unprepared("CREATE FUNCTION fixture_commit_fault() RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE state text := current_setting('fixture.fail_state',true);
            BEGIN
                IF state IN ('40P01','40001','23514') THEN
                    RAISE EXCEPTION 'fixture deferred conflict' USING ERRCODE=state;
                END IF;
                RETURN NEW;
            END; \$\$;
            CREATE CONSTRAINT TRIGGER fixture_commit_fault AFTER UPDATE ON fixture_finance
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fixture_commit_fault();");
        $this->db->statement('CREATE TABLE fixture_wire_receipts (physical_attempt_id uuid PRIMARY KEY,bytes int NOT NULL CHECK(bytes=1))');
        $this->projectPrefix = false;
        $this->workers = new ConcurrentWorkers($this->db, $this->fixtureSchema, dirname(__DIR__, 4));
    }

    protected function projectPrefix(): void
    {
        $this->projectPrefix = true;
        $this->app->instance(OwnerAuthority::class, new ProjectOwnerAuthority($this->app->make(ConnectionScope::class)));
        $this->app->forgetInstance(ClaimSend::class);
        $this->claims = $this->app->make(ClaimSend::class);
        $this->reservations = $this->app->make(ReservationService::class);
        $this->queue = $this->app->make(DemandQueue::class);
        $this->operations = $this->app->make(OperationCommands::class);
    }

    protected function matrixPrepared(bool $reserve = true, DemandPurpose $purpose = DemandPurpose::Ordinary, ?array $intent = null): array
    {
        $intent ??= $this->quotaIntent(purpose: $purpose);
        $policy = \selectionPolicy($this->tenant, $this->catalog['profile'], $this->catalog['target']);
        $input = $this->reservations->prepare($intent['request'], $intent['operation'], $intent['route'], $policy, $this->work);
        $credential = $input->route->endpointId === null ? null : new ResolvedSecret(new CredentialRef('fixture:proxy', 1), 'fixture', 'fixture-proxy-secret');
        $wire = $this->sealer->seal($input->request, $input->route, fixtureDestination($this->transactions->scope, $input->request), new TlsTrustConfiguration, $credential);
        $input = $this->reservations->prepare($wire->request, $intent['operation'], $intent['route'], $policy, $this->work);
        $ticket = $this->reservations->enqueue($input, $this->work);
        $permit = $reserve ? $this->reservations->reserve($ticket, $input) : $ticket;

        return [$permit, $input, $wire];
    }

    protected function matrixPayload(array $prepared): array
    {
        [$permit,$input] = $prepared;

        return ['operation' => $input->operation->operationId->value, 'attempt' => $input->request->physicalAttemptId->value,
            'deadline' => $input->request->deadlineAt->format('U.u'), 'route' => $input->selection->routeId->value,
            'permit' => $permit instanceof ScheduledPermit ? $permit->permitId->value : null, 'endpoint' => $input->route->endpointId?->value,
            'budget' => $this->originBudget->value, 'head' => $this->policyHead ?? null, 'project_prefix' => $this->projectPrefix];
    }
}
