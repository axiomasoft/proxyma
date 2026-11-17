<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
interface ResponseBody
{
    public function byteLength(): int;

    public function contentHash(): ContentHash;
}
