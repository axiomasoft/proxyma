<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Http;

use Closure;
use InvalidArgumentException;
use JsonException;
use Packman\Attributes\Api;
use Proxyma\Infrastructure\Database\ConnectionScope;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

#[Api(since: '1.0')]
final readonly class DnsResolver
{
    private const int MAX_ADDRESSES = 16;

    /** @param (Closure(string, int): list<string>)|null $fixture */
    public function __construct(private ConnectionScope $scope, private ?Closure $fixture = null) {}

    /** @return list<string> */
    public function resolve(string $host, int $timeoutMs): array
    {
        if ($timeoutMs < 1) {
            throw new InvalidArgumentException('DNS timeout must be positive.');
        }
        self::assertLookupHost($host);
        $this->scope->assertOutsideTransaction();
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        if ($this->fixture !== null) {
            return ($this->fixture)($host, $timeoutMs);
        }
        $script = <<<'PHP'
$records = dns_get_record($argv[1], DNS_A | DNS_AAAA);
if ($records === false) { exit(1); }
$addresses = [];
foreach ($records as $record) { if (isset($record['ip'])) $addresses[] = $record['ip']; if (isset($record['ipv6'])) $addresses[] = $record['ipv6']; }
echo json_encode($addresses, JSON_THROW_ON_ERROR);
PHP;
        $seconds = $timeoutMs / 1000;
        $process = new Process([PHP_BINARY, '-r', $script, rtrim($host, '.').'.'], env: self::resolverEnvironment());
        $process->setTimeout($seconds);
        $process->setIdleTimeout($seconds);
        try {
            $process->run();
        } catch (Throwable) {
            throw new RuntimeException('DNS resolution failed.');
        }
        if (! $process->isSuccessful()) {
            throw new RuntimeException('DNS resolution failed.');
        }
        try {
            $result = json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Invalid DNS response.');
        }
        if (! is_array($result) || ! array_is_list($result) || count($result) > self::MAX_ADDRESSES) {
            throw new RuntimeException('Invalid DNS response.');
        }
        $addresses = [];
        foreach ($result as $address) {
            if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
                throw new RuntimeException('Invalid DNS response.');
            }
            $addresses[] = $address;
        }

        return $addresses;
    }

    private static function assertLookupHost(string $host): void
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return;
        }
        $host = str_ends_with($host, '.') ? substr($host, 0, -1) : $host;
        if (strlen($host) > 253 || preg_match('/^[0-9.]+$/D', $host) === 1
            || preg_match('/^(?:0x[0-9a-f]+|[0-9]+)(?:\.|$)/i', $host) === 1
            || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/Di', $host) !== 1) {
            throw new InvalidArgumentException('DNS host is invalid.');
        }
    }

    /** @return array<string, false> */
    private static function resolverEnvironment(): array
    {
        // Symfony Process inherits missing variables, and false explicitly removes them.
        $environment = [];
        foreach (array_keys(getenv() + $_ENV) as $name) {
            if (! is_string($name)) {
                throw new RuntimeException('DNS resolution failed.');
            }
            $environment[$name] = false;
        }

        return $environment;
    }
}
