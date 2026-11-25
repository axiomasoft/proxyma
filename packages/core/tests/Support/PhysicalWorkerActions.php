<?php

declare(strict_types=1);

use Proxyma\Application\Execution\DestinationPolicy;
use Proxyma\Application\Recovery\CompletionCoordinator;
use Proxyma\Domain\Execution\ExecutionContext;
use Proxyma\Domain\Execution\OperationCorrelation;
use Proxyma\Domain\Execution\OwnerRef;
use Proxyma\Domain\Execution\PreparedHeaders;
use Proxyma\Domain\Execution\RequestTimeoutPolicy;
use Proxyma\Domain\Execution\RouteSelection;
use Proxyma\Domain\Execution\SafeUrl;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireBody;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Infrastructure\Database\CompletionObservationReducer;
use Proxyma\Infrastructure\Database\ReconcileRepository;
use Proxyma\Infrastructure\Http\DnsResolver;
use Proxyma\Infrastructure\Http\PhysicalHttpTransport;
use Proxyma\Infrastructure\Http\TlsTrust;
use Proxyma\Tests\Support\FixtureWorkBudget;

$url = new SafeUrl($payload['url']);
if ($url->origin->host !== 'origin.test' || $url->origin->scheme !== 'http') {
    throw new RuntimeException('Physical recovery worker accepts only its loopback fixture.');
}
$operation = new OperationCorrelation(new Uuid($root->id),
    new OwnerRef($tenant, $root->owner_type, $root->owner_id, $root->owner_token, (int) $root->owner_generation),
    DateTimeImmutable::createFromFormat('U.u', $payload['deadline']), $root->external_operation_key);
$target = $catalog->findTarget($tenant, new Uuid($root->target_id));
$policy = new DestinationPolicy(new DnsResolver($scope, static fn (): array => ['127.0.0.1']),
    [$target->id->value => ['origin.test' => ['127.0.0.1']]], [$url->origin->port]);
$destination = $policy->resolve($url, new ReflectionClass(ExecutionContext::class)->newInstanceWithoutConstructor(), 500, $target);
$request = new WireRequest($operation->operationId, new Uuid($payload['attempt']), $target->id, 1, 'POST', $url,
    new RequestTimeoutPolicy(500, 3000, 4000, 0, maxAttempts: 3, maxDistinctRoutes: 3), $operation->deadlineAt,
    new PreparedHeaders(['Authorization' => 'Bearer fixture-provider-secret', 'X-Fixture' => 'sealed']), WireBody::fromString('sealed-body'));
$route = $catalog->findRoute($tenant, new Uuid($payload['route']));
$tls = new TlsTrust($scope, ['origin.test' => dirname(__DIR__, 4).'/tests/Fixtures/Http/certificates/ca.pem'])->tlsConfigurationFor($url->origin, $target->id);
$wire = $sealer->seal($request, $route, $destination, $tls);
$selection = new RouteSelection($route->id, $route->kind, new Uuid($root->profile_id), $route->endpointId, $route->credentialVersion, $route->egress);
$input = $service->prepare($wire->request, $operation, $selection, selectionPolicy($tenant, $selection->profileId, $target->id), new FixtureWorkBudget);
$demand = $demands->byAttempt($tenant, $operation->operationId, $request->physicalAttemptId);
$row = $db->table('pxm_permits')->where('id', (new Uuid($payload['permit']))->value)->first();
$permit = $permits->result($row, $demands->ticket($demand, $url->origin->budgetScopeKey()));
$capability = $claims->activate($claims->claim($permit, $input, $wire));
echo "authorized\n";
flush();
if ($payload['physical_checkpoint'] === 'after_authorization') {
    $db->select("SELECT pg_advisory_lock(hashtextextended('fixture:physical-start',0))");
}
$result = new PhysicalHttpTransport($scope, $sealer)->sendOnce($capability);
if (! $result->wasDelivered()) {
    throw new RuntimeException('Actual fixture response failed: '.$result->failure?->value);
}
$repository = new ReconcileRepository($scope, $locks, $demands, $budgets, $quotas);
new CompletionCoordinator($transactions, $repository, $access, $resolver, new CompletionObservationReducer($scope))
    ->record($operation->operationId, $request->physicalAttemptId, $result);
echo "completed\n";
