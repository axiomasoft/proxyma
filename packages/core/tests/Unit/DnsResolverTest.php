<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Http\DnsResolver;
use Symfony\Component\Process\Process;

function dnsResolver(?Closure $fixture = null): DnsResolver
{
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('inTransaction')->andReturn(false);

    return new DnsResolver(new ConnectionScope(new Connection($pdo), new DatabaseTransactionsManager), $fixture);
}

it('rejects a malformed host before lookup', function (string $host): void {
    $resolver = dnsResolver(static fn (): array => ['8.8.8.8']);
    expect(fn (): array => $resolver->resolve($host, 100))->toThrow(InvalidArgumentException::class, 'DNS host is invalid.');
})->with([
    '',
    'example.test/path',
    "example.test\0",
    'user@example.test',
    '127.0.0.01',
    '0x7f000001',
    'example.test..',
    str_repeat('a', 254),
]);

it('returns a literal address without calling the fixture', function (): void {
    $resolver = dnsResolver(static function (): array {
        throw new RuntimeException('fixture must not run');
    });
    expect($resolver->resolve('8.8.8.8', 100))->toBe(['8.8.8.8'])
        ->and($resolver->resolve('2001:db8::1', 100))->toBe(['2001:db8::1']);
});

it('rejects a non-positive timeout', function (): void {
    expect(fn (): array => dnsResolver()->resolve('example.test', 0))->toThrow(InvalidArgumentException::class, 'DNS timeout must be positive.');
});

it('preserves the host on the fixture path', function (string $host): void {
    $seen = null;
    $resolver = dnsResolver(static function (string $host, int $timeoutMs) use (&$seen): array {
        $seen = [$host, $timeoutMs];

        return ['8.8.8.8'];
    });
    expect($resolver->resolve($host, 250))->toBe(['8.8.8.8'])
        ->and($seen)->toBe([$host, 250]);
})->with(['Example.TEST', 'example.test.']);

it('removes inherited host variables from the resolver process', function (): void {
    $name = 'PROXYMA_DNS_ENV_TEST';
    putenv($name.'=process-canary');
    $_ENV[$name] = 'env-canary';
    $_ENV[$name.'_ENV_ONLY'] = 'env-only-canary';
    try {
        $environment = new ReflectionMethod(DnsResolver::class, 'resolverEnvironment')->invoke(null);
        $process = new Process([PHP_BINARY, '-r', 'exit(getenv($argv[1]) === false && getenv($argv[2]) === false ? 0 : 1);', $name, $name.'_ENV_ONLY'], env: $environment);
        expect($process->run())->toBe(0);
    } finally {
        putenv($name);
        unset($_ENV[$name], $_ENV[$name.'_ENV_ONLY']);
    }
});
