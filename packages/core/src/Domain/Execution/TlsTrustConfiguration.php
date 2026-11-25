<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class TlsTrustConfiguration
{
    public function __construct(
        public bool $verifyPeer = true,
        public bool $verifyHost = true,
        public ?string $caBundlePath = null,
        public ?ContentHash $pinnedCertificateSha256 = null,
        public ?ContentHash $caBundleSha256 = null,
    ) {
        if (! $verifyPeer || ! $verifyHost) {
            throw new InvalidArgumentException('Peer and host TLS verification cannot be disabled in Proxyma.');
        }

        if ($caBundlePath !== null && ($caBundlePath === '' || preg_match('/[\x00-\x1f\x7f]/', $caBundlePath) === 1)) {
            throw new InvalidArgumentException('CA bundle path must be a valid non-empty file path.');
        }
    }
}
