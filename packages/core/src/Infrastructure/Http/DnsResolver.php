<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Http;

use Closure;
use InvalidArgumentException;
use JsonException;
use Packman\Attributes\Api;
use Proxyma\Infrastructure\Database\ConnectionScope;
use RuntimeException;
use Throwable;

#[Api(since: '1.0')]
final readonly class DnsResolver
{
    private const int MAX_ADDRESSES = 16;

    private const int MAX_OUTPUT_BYTES = 1024;

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
foreach ($records as $record) {
    $address = $record['ip'] ?? $record['ipv6'] ?? null;
    if ($address === null) { continue; }
    if (count($addresses) >= (int) $argv[2]) { exit(1); }
    $addresses[] = $address;
}
echo json_encode($addresses, JSON_THROW_ON_ERROR);
PHP;
        $output = self::runLookup($script, rtrim($host, '.').'.', $timeoutMs);
        try {
            $result = json_decode($output, true, 8, JSON_THROW_ON_ERROR);
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

    private static function runLookup(string $script, string $host, int $timeoutMs): string
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $deadline = hrtime(true) + $timeoutMs * 1_000_000;
        try {
            $process = @proc_open(
                [PHP_BINARY, '-r', $script, $host, (string) self::MAX_ADDRESSES],
                [['file', $null, 'r'], ['pipe', 'w'], ['file', $null, 'w']],
                $pipes,
                env_vars: [],
            );
        } catch (Throwable) {
            throw new RuntimeException('DNS resolution failed.');
        }
        if (! is_resource($process)) {
            throw new RuntimeException('DNS resolution failed.');
        }

        try {
            // The child bounds its output below the pipe capacity before writing.
            $status = proc_get_status($process);
            while ($status['running']) {
                if (hrtime(true) >= $deadline) {
                    proc_terminate($process, 9);
                    throw new RuntimeException('DNS resolution failed.');
                }
                usleep(1000);
                $status = proc_get_status($process);
            }
            if ($status['exitcode'] !== 0) {
                throw new RuntimeException('DNS resolution failed.');
            }
            $output = stream_get_contents($pipes[1], self::MAX_OUTPUT_BYTES + 1);
            if (! is_string($output) || strlen($output) > self::MAX_OUTPUT_BYTES) {
                throw new RuntimeException('Invalid DNS response.');
            }

            return $output;
        } finally {
            fclose($pipes[1]);
            proc_close($process);
        }
    }
}
