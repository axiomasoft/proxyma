<?php

declare(strict_types=1);

namespace Proxyma\Domain\Outcomes;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Packman\Attributes\Api;
use Proxyma\Domain\Execution\RetryAt;

/**
 * Безопасный парсер заголовка Retry-After (delta-seconds или HTTP-date) с верхним потолком.
 * Исключает целочисленное переполнение; чрезмерные или битые значения помечаются явно.
 */
#[Api(since: '1.0')]
final readonly class RetryAfterParser
{
    private const array HTTP_DATE_FORMATS = [
        DateTimeInterface::RFC7231,
        DateTimeInterface::RFC1123,
        DateTimeInterface::RFC1036,
        DateTimeInterface::RFC850,
        'D, d M Y H:i:s T',
        'D, d-M-y H:i:s T',
        'D M j H:i:s Y',
        'D M  j H:i:s Y',
    ];

    public function __construct(
        public int $maxCeilingSeconds = 86_400,
        public int $fallbackSeconds = 60,
        public int $minSeconds = 1,
    ) {}

    public function parse(?string $headerValue, DateTimeImmutable $now): RetryAfterAdvice
    {
        $utcNow = $now->setTimezone(new DateTimeZone('UTC'));
        $fallback = max($this->minSeconds, min($this->fallbackSeconds, $this->maxCeilingSeconds));

        if ($headerValue === null || trim($headerValue) === '') {
            return new RetryAfterAdvice(
                retryAt: new RetryAt($utcNow->modify("+{$fallback} seconds")),
                delaySeconds: $fallback,
                fromHeader: false,
            );
        }

        $trimmed = trim($headerValue);

        if (preg_match('/^\d+$/D', $trimmed) === 1) {
            $significant = ltrim($trimmed, '0');
            if ($significant === '') {
                $delay = $this->minSeconds;

                return new RetryAfterAdvice(
                    retryAt: new RetryAt($utcNow->modify("+{$delay} seconds")),
                    delaySeconds: $delay,
                    fromHeader: true,
                    rawRequestedSeconds: 0,
                );
            }

            if (strlen($significant) > 9) {
                $clamped = $this->maxCeilingSeconds;

                return new RetryAfterAdvice(
                    retryAt: new RetryAt($utcNow->modify("+{$clamped} seconds")),
                    delaySeconds: $clamped,
                    fromHeader: true,
                    isExcessive: true,
                    rawRequestedSeconds: PHP_INT_MAX,
                );
            }

            $requested = (int) $significant;
            if ($requested > $this->maxCeilingSeconds) {
                $clamped = $this->maxCeilingSeconds;

                return new RetryAfterAdvice(
                    retryAt: new RetryAt($utcNow->modify("+{$clamped} seconds")),
                    delaySeconds: $clamped,
                    fromHeader: true,
                    isExcessive: true,
                    rawRequestedSeconds: $requested,
                );
            }

            $delay = max($this->minSeconds, $requested);

            return new RetryAfterAdvice(
                retryAt: new RetryAt($utcNow->modify("+{$delay} seconds")),
                delaySeconds: $delay,
                fromHeader: true,
                rawRequestedSeconds: $requested,
            );
        }

        $parsedDate = $this->parseHttpDate($trimmed);
        if ($parsedDate !== null) {
            $delta = $parsedDate->getTimestamp() - $utcNow->getTimestamp();
            if ($delta > $this->maxCeilingSeconds) {
                $clamped = $this->maxCeilingSeconds;

                return new RetryAfterAdvice(
                    retryAt: new RetryAt($utcNow->modify("+{$clamped} seconds")),
                    delaySeconds: $clamped,
                    fromHeader: true,
                    isExcessive: true,
                    rawRequestedSeconds: $delta,
                );
            }

            $delay = max($this->minSeconds, $delta);

            return new RetryAfterAdvice(
                retryAt: new RetryAt($utcNow->modify("+{$delay} seconds")),
                delaySeconds: $delay,
                fromHeader: true,
                rawRequestedSeconds: max(0, $delta),
            );
        }

        return new RetryAfterAdvice(
            retryAt: new RetryAt($utcNow->modify("+{$fallback} seconds")),
            delaySeconds: $fallback,
            fromHeader: true,
            isInvalid: true,
        );
    }

    private function parseHttpDate(string $value): ?DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        foreach (self::HTTP_DATE_FORMATS as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value, $utc);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->setTimezone($utc);
            }
        }

        return null;
    }
}
