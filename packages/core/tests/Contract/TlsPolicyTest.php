<?php

declare(strict_types=1);

use Proxyma\Contracts\TlsTrustPolicy;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\CredentialRef;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Execution\ContentHash;
use Proxyma\Domain\Execution\ResolvedSecret;
use Proxyma\Domain\Execution\SealedWire;
use Proxyma\Domain\Execution\TlsTrustConfiguration;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Http\TlsTrust;
use Proxyma\Tests\Support\CatalogTestCase;
use Proxyma\Tests\Support\ClaimFixtures;
use Proxyma\Tests\Support\DemandFixtures;
use Proxyma\Tests\Support\ReservationFixtures;

require_once __DIR__.'/../Support/CatalogTestCase.php';
require_once __DIR__.'/../Support/SelectionFixtures.php';
require_once __DIR__.'/../Support/DemandFixtures.php';
require_once __DIR__.'/../Support/ReservationFixtures.php';
require_once __DIR__.'/../Support/ClaimFixtures.php';

uses(CatalogTestCase::class, DemandFixtures::class, ReservationFixtures::class, ClaimFixtures::class);

beforeEach(function (): void {
    $this->initializeClaimFixture();
    $this->caPath = dirname(__DIR__, 4).'/scripts/test-fixture-cert.pem';
    $this->tls = new TlsTrust($this->transactions->scope);
});

it('default policy использует system roots с обязательной проверкой hostname и peer', function (): void {
    $policy = $this->app->make(TlsTrustPolicy::class);
    foreach ([$policy->tlsConfigurationFor(CanonicalOrigin::of('https', 'example.test'), Uuid::random()),
        $policy->proxyConfigurationFor('proxy.test')] as $configuration) {
        expect($configuration->verifyPeer)->toBeTrue()->and($configuration->verifyHost)->toBeTrue()
            ->and($configuration->caBundlePath)->toBeNull()->and($configuration->caBundleSha256)->toBeNull();
    }
});

it('verify=false невозможно для origin и proxy', function (bool $peer, bool $host): void {
    expect(fn () => new TlsTrustConfiguration(verifyPeer: $peer, verifyHost: $host))->toThrow(InvalidArgumentException::class);
})->with([[false, true], [true, false], [false, false]]);

it('ищет CA по normalized original host независимо от target ID и сохраняет hash bundle', function (string $configured, string $requested): void {
    $policy = new TlsTrust($this->transactions->scope, [$configured => $this->caPath]);
    $configuration = $policy->tlsConfigurationFor(CanonicalOrigin::of('https', $requested), Uuid::random());
    expect($configuration->caBundlePath)->toBe($this->caPath)
        ->and($configuration->caBundleSha256->hex)->toBe(hash_file('sha256', $this->caPath))
        ->and($configuration->verifyPeer)->toBeTrue()->and($configuration->verifyHost)->toBeTrue();
    $anotherTarget = $policy->tlsConfigurationFor(CanonicalOrigin::of('https', $requested), Uuid::random());
    expect($anotherTarget->caBundleSha256->hex)->toBe($configuration->caBundleSha256->hex);
})->with([['EXAMPLE.TEST.', 'example.test'], ['bücher.test', 'xn--bcher-kva.test'], ['[2001:db8::1]', '2001:db8::1']]);

it('origin и proxy trust разделены даже при одинаковом host и config wiring', function (): void {
    $this->app['config']->set('proxyma.tls.origin_bundles', ['EXAMPLE.TEST.' => $this->caPath]);
    $originOnly = $this->app->make(TlsTrustPolicy::class);
    expect($originOnly->tlsConfigurationFor(CanonicalOrigin::of('https', 'example.test'), Uuid::random())->caBundlePath)->toBe($this->caPath)
        ->and($originOnly->proxyConfigurationFor('example.test')->caBundlePath)->toBeNull();
    $proxyOnly = new TlsTrust($this->transactions->scope, [], ['example.test' => $this->caPath]);
    expect($proxyOnly->tlsConfigurationFor(CanonicalOrigin::of('https', 'example.test'), Uuid::random())->caBundlePath)->toBeNull()
        ->and($proxyOnly->proxyConfigurationFor('EXAMPLE.TEST.')->caBundlePath)->toBe($this->caPath);
});

it('не делает fallback с отсутствующего или malformed configured bundle', function (string $kind): void {
    $path = $kind === 'missing' ? '/nonexistent/proxyma-ca.pem' : __FILE__;
    $policy = new TlsTrust($this->transactions->scope, ['example.test' => $path], ['proxy.test' => $path]);
    expect(fn () => $policy->tlsConfigurationFor(CanonicalOrigin::of('https', 'example.test'), Uuid::random()))->toThrow(InvalidArgumentException::class);
    expect(fn () => $policy->proxyConfigurationFor('proxy.test'))->toThrow(InvalidArgumentException::class);
})->with(['missing', 'malformed']);

it('не принимает false, пустые paths, userinfo или ambiguous duplicate host configuration', function (): void {
    foreach ([['example.test' => false], ['example.test' => ''], ['example.test' => "bad\0path"],
        ['user:pass@example.test' => $this->caPath], ['example.test' => $this->caPath, 'EXAMPLE.TEST.' => $this->caPath]] as $map) {
        expect(fn () => new TlsTrust($this->transactions->scope, $map))->toThrow(InvalidArgumentException::class);
        expect(fn () => new TlsTrust($this->transactions->scope, [], $map))->toThrow(InvalidArgumentException::class);
    }
});

it('TLS trust resolution не выполняет file I/O в SQL transaction', function (): void {
    $policy = new TlsTrust($this->transactions->scope, ['example.test' => $this->caPath]);
    $this->db->beginTransaction();
    try {
        expect(fn () => $policy->tlsConfigurationFor(CanonicalOrigin::of('https', 'example.test'), Uuid::random()))
            ->toThrow(LogicException::class, 'transactionLevel zero');
        expect(fn () => $policy->proxyConfigurationFor('proxy.test'))->toThrow(LogicException::class, 'transactionLevel zero');
    } finally {
        $this->db->rollBack();
    }
});

it('sealing связывает proxy trust и содержимое CA, а verify под locks остаётся pure', function (): void {
    $intent = $this->demandOperation();
    $input = $this->reservationInput($intent);
    $destination = \Proxyma\Tests\Support\fixtureDestination($this->transactions->scope, $intent['request']);
    $policy = new TlsTrust($this->transactions->scope, ['example.test' => $this->caPath], ['proxy.test' => $this->caPath]);
    $tls = $policy->tlsConfigurationFor($intent['request']->url->origin, $intent['request']->targetId);
    $proxy = $policy->proxyConfigurationFor('proxy.test');
    $wire = $this->sealer->seal($intent['request'], $input->route, $destination, $tls, proxyTls: $proxy);
    $changedProxy = new SealedWire($wire->request, $wire->route, $destination, $tls, proxyTls: new TlsTrustConfiguration);
    $changedBundle = new SealedWire($wire->request, $wire->route, $destination,
        new TlsTrustConfiguration(caBundlePath: $this->caPath, caBundleSha256: ContentHash::of('changed')), proxyTls: $proxy);
    $this->db->transaction(function () use ($wire, $changedProxy, $changedBundle): void {
        $this->sealer->verify($wire);
        expect(fn () => $this->sealer->verify($changedProxy))->toThrow(AdmissionRejected::class, 'wire_seal_conflict');
        expect(fn () => $this->sealer->verify($changedBundle))->toThrow(AdmissionRejected::class, 'wire_seal_conflict');
    });
    expect($changedProxy->connectionReuseKey())->not->toBe($wire->connectionReuseKey());
});

it('connection reuse identity разделяет tenant и credential generations', function (): void {
    $this->proxyClaimFixture();
    [, , $wire] = $this->prepareClaim();
    $route = $wire->route;
    foreach (['tenant', 'version'] as $change) {
        $changedRoute = new Route($route->id, $change === 'tenant' ? new TenantKey('foreign') : $route->tenantKey,
            $route->routeKey, $route->kind, $route->endpointId, $route->enabled, $route->egress,
            credentialVersion: $change === 'version' ? 2 : 1, revision: $route->revision, endpointRevision: $route->endpointRevision);
        $credential = $change === 'version' ? new ResolvedSecret(new CredentialRef('fixture:proxy', 2), 'fixture', 'new-password') : $wire->credential;
        $changed = new SealedWire($wire->request, $changedRoute, $wire->destination, $wire->tls, $credential);
        expect($changed->connectionReuseKey())->not->toBe($wire->connectionReuseKey());
    }
});
