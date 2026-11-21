<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Security;

use LogicException;
use Packman\Attributes\Internal;
use Proxyma\Domain\Execution\ResolvedSecret;
use SensitiveParameter;
use Throwable;

/** Явная граница диагностик: raw request, exception traces и transport options не логируются. */
#[Internal]
final readonly class SecretRedactor
{
    /** @var list<string> */
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
                    $tokens[] = $value;
                    $tokens[] = rawurlencode($value);
                    $tokens[] = urlencode($value);
                    $tokens[] = base64_encode($value);
                }
            }
        }
        $tokens = array_values(array_unique($tokens));
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
                if (is_string($key)) {
                    $sanitizedKey = $this->context($key, $depth + 1);
                    $name = is_string($sanitizedKey) ? $sanitizedKey : '[redacted]';
                } else {
                    $name = $key;
                }
                $safe[$name] = is_string($key) && preg_match('/auth|cookie|secret|password|token|credential|body|payload|headers|ciphertext/i', $key) === 1
                    ? '[redacted]' : $this->context($item, $depth + 1);
            }

            return $safe;
        }
        if (is_string($value)) {
            $value = str_replace($this->tokens, '[redacted]', $value);
            $value = preg_replace('~([a-z][a-z0-9+.-]*://)[^/\s@]+@~i', '$1[redacted]@', $value) ?? '[redacted]';
            $value = preg_replace('~([a-z][a-z0-9+.-]*://[^\s?#]*)[?#][^\s]*~i', '$1?[redacted]', $value) ?? '[redacted]';
            if (strlen($value) > 65536) {
                return mb_strcut($value, 0, 65536, 'UTF-8').'[truncated]';
            }

            return $value;
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
