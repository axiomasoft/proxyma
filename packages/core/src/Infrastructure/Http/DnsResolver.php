<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Http;

use Closure;
use InvalidArgumentException;
use Packman\Attributes\Api;
use Proxyma\Infrastructure\Database\ConnectionScope;
use RuntimeException;
use Symfony\Component\Process\Process;

#[Api(since: '1.0')]
final readonly class DnsResolver
{
    /** @param (Closure(string, int): list<string>)|null $fixture */
    public function __construct(private ConnectionScope $scope, private ?Closure $fixture = null) {}

    /** @return list<string> */
    public function resolve(string $host, int $timeoutMs): array
    {
        $this->scope->assertOutsideTransaction();
        if ($timeoutMs < 1) {
            throw new InvalidArgumentException('DNS timeout must be positive.');
        }
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
        $process = new Process([PHP_BINARY, '-r', $script, $host]);
        $process->setTimeout($timeoutMs / 1000);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('DNS resolution failed.');
        }
        $result = json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($result) || ! array_is_list($result)) {
            throw new RuntimeException('Invalid DNS response.');
        }
        $addresses = [];
        foreach ($result as $address) {
            if (! is_string($address)) {
                throw new RuntimeException('Invalid DNS response.');
            }
            $addresses[] = $address;
        }

        return $addresses;
    }
}
