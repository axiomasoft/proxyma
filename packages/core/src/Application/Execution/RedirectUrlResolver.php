<?php

declare(strict_types=1);

namespace Proxyma\Application\Execution;

use InvalidArgumentException;
use Proxyma\Domain\Execution\SafeUrl;

/** @internal Общая RFC relative-reference обработка обоих execution entry points. */
final class RedirectUrlResolver
{
    public function resolve(SafeUrl $baseUrl, string $location): SafeUrl
    {
        $trimmed = trim($location);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Redirect Location header must not be empty.');
        }

        $fragmentPos = strpos($trimmed, '#');
        if ($fragmentPos !== false) {
            $trimmed = substr($trimmed, 0, $fragmentPos);
        }

        if ($trimmed === '') {
            return $baseUrl;
        }

        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $trimmed) === 1) {
            return new SafeUrl($trimmed);
        }

        if (str_starts_with($trimmed, '//')) {
            return new SafeUrl($baseUrl->origin->scheme.':'.$trimmed);
        }

        $originPrefix = $baseUrl->origin->value();
        if (str_starts_with($trimmed, '/')) {
            return new SafeUrl($originPrefix.$trimmed);
        }

        $baseRevealed = $baseUrl->reveal();
        $basePath = parse_url($baseRevealed, PHP_URL_PATH);
        $basePath = is_string($basePath) && $basePath !== '' ? $basePath : '/';

        if (str_starts_with($trimmed, '?')) {
            return new SafeUrl($originPrefix.$basePath.$trimmed);
        }

        $lastSlash = strrpos($basePath, '/');
        $dir = $lastSlash === false ? '/' : substr($basePath, 0, $lastSlash + 1);

        return new SafeUrl($originPrefix.$this->normalizeRelativePath($dir.$trimmed));
    }

    private function normalizeRelativePath(string $path): string
    {
        $query = '';
        $qPos = strpos($path, '?');
        if ($qPos !== false) {
            $query = substr($path, $qPos);
            $path = substr($path, 0, $qPos);
        }

        $segments = explode('/', $path);
        $resolved = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($resolved);

                continue;
            }
            $resolved[] = $segment;
        }

        return '/'.implode('/', $resolved).$query;
    }
}
