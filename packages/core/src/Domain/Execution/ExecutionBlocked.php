<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ExecutionBlocked implements ExecutionResult
{
    private const array FORBIDDEN_SECRET_KEYS = [
        'password',
        'secret',
        'token',
        'owner_token',
        'api_key',
        'authorization',
        'proxy_authorization',
        'cookie',
        'set_cookie',
    ];

    /** @var array<string, int|float|string|bool|null> */
    public array $safeDetails;

    /**
     * @param  array<string, int|float|string|bool|null>  $safeDetails
     * @param  list<Uuid>  $attemptIds
     */
    public function __construct(
        public BlockedReason $reason,
        array $safeDetails = [],
        public array $attemptIds = [],
    ) {
        $this->safeDetails = self::validateSafeDetails($safeDetails);
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, int|float|string|bool|null>
     */
    public static function validateSafeDetails(array $details): array
    {
        $normalized = [];
        foreach ($details as $key => $value) {
            if (preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $key) !== 1) {
                throw new InvalidArgumentException('Safe detail keys must be canonical lowercase identifiers.');
            }

            foreach (self::FORBIDDEN_SECRET_KEYS as $forbidden) {
                if ($key === $forbidden || str_contains($key, $forbidden)) {
                    throw new InvalidArgumentException("Safe details must not contain secret-bearing key '{$key}'.");
                }
            }

            if ($value !== null && ! is_scalar($value)) {
                throw new InvalidArgumentException("Safe detail value for '{$key}' must be scalar or null.");
            }

            $normalized[$key] = $value;
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }
}
