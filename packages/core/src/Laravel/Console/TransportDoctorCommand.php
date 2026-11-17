<?php

declare(strict_types=1);

namespace Proxyma\Laravel\Console;

use Illuminate\Console\Command;
use LogicException;
use Packman\Attributes\Api;
use Proxyma\Domain\Catalog\ProxyProtocol;
use Proxyma\Infrastructure\Http\TransportDoctor;

#[Api(since: '1.0')]
final class TransportDoctorCommand extends Command
{
    protected $signature = 'proxyma:transport-doctor {--protocol=* : Required proxy protocols (http, https, socks5)}';

    protected $description = 'Check local cURL support before enabling proxy protocols; performs no network I/O';

    public function handle(): int
    {
        $rawProtocols = $this->option('protocol');
        $protocols = $rawProtocols !== [] ? $rawProtocols : ['http', 'https', 'socks5'];
        foreach ($protocols as $name) {
            if (! is_string($name)) {
                $this->error('Unknown proxy protocol.');

                return self::INVALID;
            }
            $protocol = ProxyProtocol::tryFrom($name);
            if ($protocol === null) {
                $this->error('Unknown proxy protocol.');

                return self::INVALID;
            }
            try {
                TransportDoctor::assertProtocol($protocol);
            } catch (LogicException $error) {
                $this->error($error->getMessage());

                return self::FAILURE;
            }
            $this->line($name.': supported; qualify against local contract fixtures before use');
        }

        return self::SUCCESS;
    }
}
