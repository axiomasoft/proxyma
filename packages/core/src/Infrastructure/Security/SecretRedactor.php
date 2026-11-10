<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Security;

use LogicException;
use Proxyma\Domain\Execution\ResolvedSecret;
use SensitiveParameter;
use Throwable;

/** Явная граница диагностик: raw request, exception traces и transport options не логируются. */
final readonly class SecretRedactor
{
    private array $tokens;

    /** @param list<ResolvedSecret> $secrets */
    public function __construct(#[SensitiveParameter] array $secrets = [])
    {
        $tokens = [];
        foreach ($secrets as $secret) {
            $values = [$secret->revealSecret(), $secret->username, $secret->encodedUserInfo(),
                base64_encode(($secret->username ?? '').':'.$secret->revealSecret())];
            foreach ($values as $value) {
                if ($value !== null && $value !== '') {
                    array_push($tokens, $value, rawurlencode($value), urlencode($value), base64_encode($value));
                }
            }
        }
        $tokens = array_unique($tokens);
        usort($tokens, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $this->tokens = $tokens;
    }

    public function context(#[SensitiveParameter] mixed $value, int $depth = 0): mixed
    {
        if ($depth > 8) {
            return '[redacted]';
        }
        if ($value instanceof Throwable) {
            return ['exception_type' => $value::class];
        }
        if (is_object($value) || is_resource($value)) {
            return '[redacted]';
        }
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $key => $item) {
                $name = is_string($key) ? $this->context($key, $depth + 1) : $key;
                $safe[$name] = is_string($key) && preg_match('/auth|cookie|secret|password|token|credential|body|payload|headers|ciphertext/i', $key)
                    ? '[redacted]' : $this->context($item, $depth + 1);
            }

            return $safe;
        }
        if (is_string($value)) {
            $value = str_replace($this->tokens, '[redacted]', $value);
            $value = preg_replace('~(https?://)[^/\s@]+@~i', '$1[redacted]@', $value);

            return preg_replace('~(https?://[^\s?#]+)[?#][^\s]*~i', '$1?[redacted]', $value);
        }

        return $value;
    }

    public function __debugInfo(): array
    {
        return [];
    }

    public function __serialize(): array
    {
        throw new LogicException('Secret redactor cannot be serialized.');
    }
}
