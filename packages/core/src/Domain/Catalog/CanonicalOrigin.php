<?php

declare(strict_types=1);

namespace Proxyma\Domain\Catalog;

use InvalidArgumentException;
use Proxyma\Domain\Execution\ContentHash;
use Proxyma\Domain\Execution\Uuid;

final readonly class CanonicalOrigin
{
    private const array DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    private function __construct(
        public string $scheme,
        public string $host,
        public int $port,
    ) {}

    public static function of(string $scheme, string $host, ?int $port = null): self
    {
        $scheme = strtolower($scheme);
        if (! array_key_exists($scheme, self::DEFAULT_PORTS)) {
            throw new InvalidArgumentException('Origin scheme must be http or https.');
        }

        $port ??= self::DEFAULT_PORTS[$scheme];
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Origin port is out of range.');
        }

        return new self($scheme, self::normalizeHost($host), $port);
    }

    public static function fromUrl(string $url): self
    {
        if (preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new InvalidArgumentException('Origin URL must not contain whitespace or control bytes.');
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('Origin URL requires a scheme and a host.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Origin URL must not contain userinfo.');
        }

        return self::of($parts['scheme'], $parts['host'], $parts['port'] ?? null);
    }

    public function value(): string
    {
        $host = str_contains($this->host, ':') ? '['.$this->host.']' : $this->host;

        return $this->scheme.'://'.$host.':'.$this->port;
    }

    public function hash(): ContentHash
    {
        return ContentHash::of('origin:v1:'.$this->value());
    }

    public function budgetScopeKey(): string
    {
        return 'origin:'.$this->hash()->hex;
    }

    public function probeScopeKey(): string
    {
        return 'probe:'.$this->hash()->hex;
    }

    public function routeScopeKey(Uuid|string $routeId): string
    {
        $id = $routeId instanceof Uuid ? $routeId->value : $routeId;

        return 'route-origin:'.$id.':'.$this->hash()->hex;
    }

    public function egressScopeKey(EgressIdentity|string $egress): string
    {
        $key = $egress instanceof EgressIdentity ? $egress->key : $egress;

        return 'egress-origin:'.$key.':'.$this->hash()->hex;
    }

    public function equals(self $other): bool
    {
        return $this->value() === $other->value();
    }

    public static function normalizeHost(string $host): string
    {
        if (str_contains($host, '%') || str_contains($host, '\\') || preg_match('/^[0-9.]+$/D', $host) && filter_var($host, FILTER_VALIDATE_IP) === false
            || (filter_var($host, FILTER_VALIDATE_IP) === false && preg_match('/^(?:0x[0-9a-f]+|[0-9]+)(?:\.|$)/i', $host))) {
            throw new InvalidArgumentException('Ambiguous host.');
        }
        $host = rtrim(strtolower(trim($host, '[]')), '.');
        if ($host === '') {
            throw new InvalidArgumentException('Origin host must not be empty.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return strtolower((string) inet_ntop((string) inet_pton($host)));
        }

        if (preg_match('/[^\x00-\x7f]/', $host) === 1 || str_contains($host, 'xn--')) {
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_USE_STD3_RULES, INTL_IDNA_VARIANT_UTS46, $info);
            if (! is_string($ascii) || $ascii === '' || ($info['errors'] ?? 0) !== 0) {
                throw new InvalidArgumentException('Origin host is not a valid IDNA name.');
            }
            $host = strtolower($ascii);
        }

        if (strlen($host) > 253 || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/D', $host) !== 1) {
            throw new InvalidArgumentException('Origin host is not a valid DNS name.');
        }

        return $host;
    }
}
