<?php

declare(strict_types=1);

namespace Proxyma\Tests\Support;

require_once __DIR__.'/DestinationFixtures.php';

use Proxyma\Application\Admission\ClaimSend;
use Proxyma\Application\Admission\TransactionCoordinator;
use Proxyma\Application\Execution\WireSealer;
use Proxyma\Contracts\Execution\PhysicalTransport;
use Proxyma\Domain\Catalog\CredentialRef;
use Proxyma\Domain\Catalog\EgressIdentity;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Execution\AttemptResult;
use Proxyma\Domain\Execution\AuthorizedWireRequest;
use Proxyma\Domain\Execution\DecodedTextBody;
use Proxyma\Domain\Execution\ResolvedSecret;
use Proxyma\Domain\Execution\ResponseDescriptor;
use Proxyma\Domain\Execution\RouteSelection;
use Proxyma\Domain\Execution\TlsTrustConfiguration;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireRequest;

final class FixturePhysicalTransport implements PhysicalTransport
{
    public int $sends = 0;

    public function sendOnce(AuthorizedWireRequest $request): AttemptResult
    {
        $request->consume();
        $this->sends++;

        return AttemptResult::delivered(new ResponseDescriptor(200, [],
            new DecodedTextBody('fixture'), 7, 7));
    }
}

trait ClaimFixtures
{
    protected function initializeClaimFixture(): void
    {
        $this->initializeReservationFixture();
        $this->reconfigureProxyma(['wire.digest_key' => 'fixture-only-32-byte-hmac-key-value']);
        $this->sealer = $this->app->make(WireSealer::class);
        $this->claims = $this->app->make(ClaimSend::class);
        $this->transactions = $this->app->make(TransactionCoordinator::class);
        $this->emitted = 0;
        $this->physical = new FixturePhysicalTransport;
    }

    protected function prepareClaim(?array $intent = null): array
    {
        $intent ??= $this->demandOperation();
        if (isset($this->proxyEndpoint)) {
            $intent['route'] = new RouteSelection($this->catalog['route'], RouteKind::Proxy,
                $this->catalog['profile'], $this->proxyEndpoint, 1, EgressIdentity::explicit('nat:fixture'));
        }
        $input = $this->reservationInput($intent);
        $credential = isset($this->proxyEndpoint) ? new ResolvedSecret(new CredentialRef('fixture:proxy', 1), 'fixture', 'fixture-proxy-secret') : null;
        $wire = $this->sealer->seal($intent['request'], $input->route, fixtureDestination($this->transactions->scope, $intent['request']), new TlsTrustConfiguration, $credential);
        $intent['request'] = $wire->request;
        $input = $this->reservationInput($intent);
        $ticket = $this->reservations->enqueue($input, $this->work);
        $permit = $this->reservations->reserve($ticket, $input);
        $this->claims->prepare($permit, $input, $wire);

        return [$permit, $input, $wire];
    }

    protected function emit($capability): void
    {
        $this->physical->sendOnce($capability);
        $this->emitted++;
    }

    protected function proxyClaimFixture(): void
    {
        $endpoint = Uuid::random();
        $this->table('endpoints')->insert(['id' => $endpoint->value, 'tenant_key' => $this->tenant->value, 'protocol' => 'http',
            'host' => 'proxy.test', 'port' => 8080, 'credential_ref' => 'fixture:proxy', 'credential_version' => 1]);
        $this->table('routes')->where('id', $this->catalog['route']->value)->update(['kind' => 'proxy', 'endpoint_id' => $endpoint->value, 'revision' => 2]);
        $this->proxyEndpoint = $endpoint;
    }

    protected function modifiedWire(WireRequest $request, array $changes): WireRequest
    {
        return new WireRequest(...array_replace([
            'operationId' => $request->operationId, 'physicalAttemptId' => $request->physicalAttemptId, 'targetId' => $request->targetId,
            'policyRevision' => $request->policyRevision, 'method' => $request->method, 'url' => $request->url, 'timeouts' => $request->timeouts,
            'deadlineAt' => $request->deadlineAt, 'headers' => $request->headers, 'body' => $request->body,
            'idempotency' => $request->idempotency, 'purpose' => $request->purpose, 'sealDigest' => $request->sealDigest,
            'trafficClass' => $request->trafficClass,
        ], $changes));
    }
}
