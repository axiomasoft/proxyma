<?php

declare(strict_types=1);

use Proxyma\Domain\Catalog\CredentialRef;
use Proxyma\Domain\Execution\ResolvedSecret;
use Proxyma\Infrastructure\Security\SecretRedactor;

it('redacts userinfo and query strings for every scheme', function (): void {
    $secret = new ResolvedSecret(new CredentialRef('opaque'), 'canary-user', 'canary-password');
    $redactor = new SecretRedactor([$secret]);
    $safe = $redactor->context('ssh://other-user:other-secret@example.test/path?x=other-canary');

    expect($safe)->toBe('ssh://[redacted]@example.test/path?[redacted]')
        ->and($redactor->context('https://canary-user:canary-password@example.test/a'))->not->toContain('canary-user', 'canary-password');
});

it('redacts a secret past the retained diagnostic prefix', function (): void {
    $secret = new ResolvedSecret(new CredentialRef('opaque'), 'canary-user', 'canary-password');
    $safe = new SecretRedactor([$secret])->context(str_repeat('x', 70000).'canary-password');

    expect($safe)->not->toContain('canary-password')
        ->and($safe)->toEndWith('[truncated]')
        ->and(strlen($safe))->toBe(65536 + strlen('[truncated]'));
});

it('keeps truncated UTF-8 diagnostics valid', function (): void {
    $safe = new SecretRedactor([])->context(str_repeat('x', 65535).'ёй');

    expect(mb_check_encoding($safe, 'UTF-8'))->toBeTrue()
        ->and($safe)->toBe(str_repeat('x', 65535).'[truncated]');
});
