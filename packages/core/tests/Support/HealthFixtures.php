<?php

declare(strict_types=1);

namespace Proxyma\Tests\Support;

use Proxyma\Application\Admission\ClaimSend;
use Proxyma\Application\Health\ProbeCoordinator;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Execution\AttemptResult;
use Proxyma\Domain\Execution\DecodedTextBody;
use Proxyma\Domain\Execution\DemandPurpose;
use Proxyma\Domain\Execution\ResponseDescriptor;
use Proxyma\Domain\Execution\Uuid;

trait HealthFixtures
{
    protected function initializeHealthFixture(): void
    {
        $this->initializeRecoveryFixture();
        $this->probes = $this->app->make(ProbeCoordinator::class);
        $this->probeBudget = new Uuid($this->budget(CanonicalOrigin::of('https', 'example.test')->probeScopeKey()));
        $this->quota->configure($this->tenant, $this->probeBudget, 2, 3, 1);
    }

    protected function healthUnknown(array $scopes = ['route_health', 'route_target_health', 'target_health']): void
    {
        foreach ($scopes as $scope) {
            $this->table($scope)->update(['state' => 'unknown']);
        }
    }

    protected function healthProbe(int $lease = 30): array
    {
        $intent = $this->quotaIntent(purpose: DemandPurpose::Probe);
        $claim = $this->probes->claim($this->reservationInput($intent), $lease);

        return [$intent, $claim];
    }

    protected function sendProbe(array $intent): array
    {
        $prepared = $this->quotaPrepared(intent: $intent);
        [$permit, $input, $wire] = $prepared;
        $candidate = $this->claims->claim($permit, $input, $wire);
        $this->emit($this->claims->activate($candidate));

        return $prepared;
    }

    protected function healthResponse(int $status, ?string $retryAfter = '120'): AttemptResult
    {
        return AttemptResult::delivered(new ResponseDescriptor($status, $retryAfter === null ? [] : ['retry-after' => [$retryAfter]],
            new DecodedTextBody('fixture'), 7, 7));
    }

    protected function narrowCooldowns(): void
    {
        $this->reconfigureProxyma(['health_cooldown_scopes' => ['route-origin', 'egress-origin']]);
        $this->app->forgetInstance(ClaimSend::class);
        $this->claims = $this->app->make(ClaimSend::class);
    }
}
