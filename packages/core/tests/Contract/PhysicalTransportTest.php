<?php

declare(strict_types=1);

use Proxyma\Domain\Catalog\ProxyProtocol;
use Proxyma\Domain\Execution\RequestTimeoutPolicy;
use Proxyma\Domain\Execution\TransportFailure;
use Proxyma\Infrastructure\Http\PhysicalHttpTransport;
use Proxyma\Infrastructure\Http\TransportDoctor;
use Proxyma\Tests\Support\CatalogTestCase;
use Proxyma\Tests\Support\ClaimFixtures;
use Proxyma\Tests\Support\DemandFixtures;
use Proxyma\Tests\Support\PhysicalFixtures;
use Proxyma\Tests\Support\QuotaFixtures;
use Proxyma\Tests\Support\ReservationFixtures;

foreach (['CatalogTestCase', 'SelectionFixtures', 'DemandFixtures', 'ReservationFixtures', 'ClaimFixtures', 'QuotaFixtures', 'PhysicalFixtures'] as $file) {
    require_once __DIR__.'/../Support/'.$file.'.php';
}
uses(CatalogTestCase::class, DemandFixtures::class, ReservationFixtures::class, ClaimFixtures::class, QuotaFixtures::class, PhysicalFixtures::class)->group('pg');
beforeEach(function (): void {
    $this->initializePhysicalFixture();
});
afterEach(function (): void {
    $this->socket?->stop();
});

it('реальный dispatch сохраняет pinned IP, Host, SNI, body и headers для каждого протокола', function (string $listener, ?string $proxy): void {
    $prepared = $this->physicalPrepared(listener: $listener, proxyListener: $proxy);
    $capability = $this->physicalCapability($prepared);
    $result = $this->transport->sendOnce($capability);
    expect($result->failure)->toBeNull()->and($result->response?->statusCode)->toBe(200)
        ->and($result->response?->body->bytes())->toBe('fixture-response')
        ->and($result->wireBytes)->toBe(16)->and($result->response?->head->headers)->not->toHaveKey('set-cookie');
    $received = $this->socket->await('origin');
    expect($received)->toHaveCount(1)->and($received[0]['bytes'])->toBeGreaterThan(0)
        ->and($received[0]['body_hash'])->toBe(hash('sha256', 'sealed-body'))->and($received[0]['body_length'])->toBe(11)
        ->and($received[0]['headers']['host'])->toBe('origin.test:'.$this->socket->ports[$listener])
        ->and($received[0]['headers']['authorization'])->toBe('Bearer fixture-provider-secret')
        ->and($received[0]['headers']['x-fixture'])->toBe('sealed')
        ->and($received[0]['headers'])->not->toHaveKey('proxy-authorization');
    if ($listener === 'tls') {
        expect(array_column($this->socket->events('sni'), 'name'))->toContain('origin.test');
    }
    if ($proxy !== null && $proxy !== 'socks') {
        expect($this->socket->events('proxy'))->toHaveCount(1);
        if ($listener === 'tls') {
            expect($this->socket->events('connect')[0]['target'])->toBe('127.0.0.1:'.$this->socket->ports[$listener]);
        } else {
            expect($this->socket->events('proxy')[0]['request'])->toBe('POST http://127.0.0.1:'.$this->socket->ports[$listener].'/ok HTTP/1.1');
        }
    }
    if ($proxy === 'proxy_tls') {
        expect(array_column($this->socket->events('sni'), 'name'))->toContain('proxy.test');
    }
    if ($proxy === 'auth') {
        expect($this->socket->events('proxy')[0]['auth_ok'])->toBeTrue();
    }
    if ($proxy === 'socks') {
        expect($this->socket->events('socks')[0])->toMatchArray(['host' => '127.0.0.1', 'atyp' => 1, 'port' => $this->socket->ports[$listener]]);
    }
    expect(fn () => $this->transport->sendOnce($capability))->toThrow(LogicException::class, 'already consumed');
    expect($this->socket->events('origin'))->toHaveCount(1);
})->with([
    'direct HTTP' => ['http', null], 'direct HTTPS' => ['tls', null],
    'HTTP forwarding' => ['http', 'proxy'], 'HTTP CONNECT' => ['tls', 'proxy'],
    'HTTPS forwarding' => ['http', 'proxy_tls'], 'HTTPS CONNECT' => ['tls', 'proxy_tls'],
    'authenticated forwarding delimiters' => ['http', 'auth'], 'authenticated CONNECT delimiters' => ['tls', 'auth'],
    'SOCKS5 local DNS HTTP' => ['http', 'socks'], 'SOCKS5 local DNS HTTPS' => ['tls', 'socks'],
]);

it('отклоняет плохой CA, hostname и expired certificate до origin bytes', function (string $listener, ?string $proxy, bool $originTrust, bool $proxyTrust): void {
    $prepared = $this->physicalPrepared(listener: $listener, proxyListener: $proxy, trustOrigin: $originTrust, trustProxy: $proxyTrust);
    $result = $this->transport->sendOnce($this->physicalCapability($prepared));
    expect($result->failure)->toBe(TransportFailure::Tls)->and($this->socket->events('origin'))->toBe([])
        ->and($result->response)->toBeNull();
    if ($proxy !== null && ! $proxyTrust) {
        expect($this->socket->events('proxy'))->toBe([]);
    }
})->with([
    'unknown system root' => ['tls', null, false, true], 'untrusted' => ['untrusted', null, true, true],
    'wrong hostname' => ['wrong', null, true, true], 'expired' => ['expired', null, true, true],
    'proxy untrusted' => ['tls', 'proxy_tls', true, false], 'proxy hostname' => ['tls', 'proxy_wrong', true, true],
    'proxy expired' => ['tls', 'proxy_expired', true, true], 'origin through trusted proxy' => ['wrong', 'proxy_tls', true, true],
]);

it('407 не уходит в origin, не повторяет auth и не переключается на direct', function (): void {
    $prepared = $this->physicalPrepared(listener: 'tls', proxyListener: 'auth', password: 'wrong');
    $result = $this->transport->sendOnce($this->physicalCapability($prepared));
    expect($result->failure)->toBe(TransportFailure::ProxyAuth)->and($this->socket->events('proxy'))->toHaveCount(1)
        ->and($this->socket->events('origin'))->toBe([])->and($this->socket->events('connect'))->toBe([]);
});

it('переменные окружения не меняют выбранный direct или proxy route', function (?string $proxy): void {
    $saved = [];
    foreach (['HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'http_proxy', 'https_proxy', 'all_proxy', 'NO_PROXY', 'no_proxy'] as $key) {
        $saved[$key] = getenv($key);
        putenv($key.'='.(str_contains(strtolower($key), 'no_proxy') ? '*' : 'http://127.0.0.1:1'));
    }
    try {
        $prepared = $this->physicalPrepared(listener: 'tls', proxyListener: $proxy);
        $result = $this->transport->sendOnce($this->physicalCapability($prepared));
        expect($result->wasDelivered())->toBeTrue()->and($this->socket->events('origin'))->toHaveCount(1)
            ->and($this->socket->events('proxy'))->toHaveCount(in_array($proxy, ['proxy', 'proxy_tls'], true) ? 1 : 0)
            ->and($this->socket->events('socks'))->toHaveCount($proxy === 'socks' ? 1 : 0);
        if ($proxy === 'socks') {
            expect($this->socket->events('socks')[0])->toMatchArray([
                'host' => '127.0.0.1', 'port' => $this->socket->ports['tls'], 'atyp' => 1,
            ]);
        }
    } finally {
        foreach ($saved as $key => $value) {
            putenv($value === false ? $key : $key.'='.$value);
        }
    }
})->with([null, 'proxy', 'proxy_tls', 'socks']);

it('физический transport не следует redirect и не повторяет HTTP failures', function (string $path, int $status): void {
    $result = $this->transport->sendOnce($this->physicalCapability($this->physicalPrepared($path)));
    expect($result->response?->statusCode)->toBe($status)->and($result->failure)->toBeNull()
        ->and($this->socket->events('origin'))->toHaveCount(1);
})->with([['/redirect', 302], ['/401', 401], ['/403', 403], ['/404', 404], ['/429', 429], ['/503', 503]]);

it('различает timeout, truncation, connect и byte limit без повторного send', function (string $path, TransportFailure $failure): void {
    $prepared = $this->physicalPrepared($path, timeouts: new RequestTimeoutPolicy(200, 200, 1000, 0));
    $transport = new PhysicalHttpTransport($this->transactions->scope, $this->sealer, 128);
    $result = $transport->sendOnce($this->physicalCapability($prepared));
    expect($result->failure)->toBe($failure)->and($this->socket->events('origin'))->toHaveCount(1);
})->with([['/stall', TransportFailure::Timeout], ['/truncated', TransportFailure::Truncated], ['/drop', TransportFailure::Connect], ['/large', TransportFailure::ByteLimit]]);

it('doctor запрещает HTTPS proxy без feature и SOCKS5h до enable', function (): void {
    expect(fn () => TransportDoctor::assertProtocol(ProxyProtocol::Https, 0))->toThrow(LogicException::class, 'lacks HTTPS-proxy')
        ->and(fn () => TransportDoctor::assertProtocol(ProxyProtocol::Socks5h))->toThrow(LogicException::class, 'SOCKS5h');
    TransportDoctor::assertProtocol(ProxyProtocol::Https);
    $this->artisan('proxyma:transport-doctor', ['--protocol' => ['http', 'https', 'socks5']])->assertExitCode(0);
    $this->artisan('proxyma:transport-doctor', ['--protocol' => ['socks5h']])->assertExitCode(1);
    $this->socket->assertNoConnections();
});

it('отказ TCP различает direct и proxy и не переключается на другой адрес', function (bool $proxy): void {
    $prepared = $this->physicalPrepared(listener: $proxy ? 'http' : 'refused', proxyListener: $proxy ? 'refused' : null);
    $result = $this->transport->sendOnce($this->physicalCapability($prepared));
    expect($result->failure)->toBe($proxy ? TransportFailure::ProxyConnect : TransportFailure::Connect)
        ->and($result->curlErrorCode)->toBe(CURLE_COULDNT_CONNECT);
    $this->socket->assertNoConnections();
})->with([true, false]);

it('сохраняет sealed path и query без скрытой нормализации cURL', function (): void {
    $path = '/a/../ok?sig=fixture%2F';
    $result = $this->transport->sendOnce($this->physicalCapability($this->physicalPrepared($path)));
    expect($result->wasDelivered())->toBeTrue()
        ->and($this->socket->events('origin')[0]['request'])->toBe('POST '.$path.' HTTP/1.1');
});
