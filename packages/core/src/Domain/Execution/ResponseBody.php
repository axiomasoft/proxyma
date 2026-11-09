<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

interface ResponseBody
{
    public function byteLength(): int;

    public function contentHash(): ContentHash;
}
