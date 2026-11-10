<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Http;

use InvalidArgumentException;
use Proxyma\Contracts\TlsTrustPolicy;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Execution\ContentHash;
use Proxyma\Domain\Execution\TlsTrustConfiguration;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Infrastructure\Database\ConnectionScope;

final readonly class TlsTrust implements TlsTrustPolicy
{
    private array $originBundles;

    private array $proxyBundles;

    /** @param array<string, string> $originBundles @param array<string, string> $proxyBundles */
    public function __construct(private ConnectionScope $scope, array $originBundles = [], array $proxyBundles = [])
    {
        $this->originBundles = $this->normalize($originBundles);
        $this->proxyBundles = $this->normalize($proxyBundles);
    }

    public function tlsConfigurationFor(CanonicalOrigin $origin, Uuid $targetId): TlsTrustConfiguration
    {
        return $this->configuration($origin->host, $this->originBundles);
    }

    public function proxyConfigurationFor(string $originalHost): TlsTrustConfiguration
    {
        return $this->configuration($originalHost, $this->proxyBundles);
    }

    private function normalize(array $bundles): array
    {
        $normalized = [];
        foreach ($bundles as $host => $path) {
            $key = CanonicalOrigin::normalizeHost((string) $host);
            if (! is_string($path) || $path === '' || isset($normalized[$key])) {
                throw new InvalidArgumentException('TLS trust requires one CA bundle path per normalized host.');
            }
            new TlsTrustConfiguration(caBundlePath: $path);
            $normalized[$key] = $path;
        }

        return $normalized;
    }

    private function configuration(string $originalHost, array $bundles): TlsTrustConfiguration
    {
        $this->scope->assertOutsideTransaction();
        $host = CanonicalOrigin::normalizeHost($originalHost);
        $path = $bundles[$host] ?? null;
        if ($path === null) {
            return new TlsTrustConfiguration;
        }
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 4 * 1024 * 1024) {
            throw new InvalidArgumentException('Configured TLS CA bundle is unavailable or invalid.');
        }
        $bytes = @file_get_contents($path);
        if (! is_string($bytes) || preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $bytes, $matches) < 1
            || trim((string) preg_replace('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', '', $bytes)) !== '') {
            throw new InvalidArgumentException('Configured TLS CA bundle is unavailable or invalid.');
        }
        foreach ($matches[0] as $certificate) {
            if (@openssl_x509_read($certificate) === false) {
                throw new InvalidArgumentException('Configured TLS CA bundle is unavailable or invalid.');
            }
        }

        return new TlsTrustConfiguration(caBundlePath: $path, caBundleSha256: ContentHash::of($bytes));
    }
}
