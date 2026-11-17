<?php

declare(strict_types=1);

namespace Proxyma\Domain\Catalog;

use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ActorRef
{
    public function __construct(public string $type, public string $id)
    {
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $type) !== 1 || $id === '' || strlen($id) > 128 || preg_match('/[\x00-\x1f\x7f]/', $id) === 1) {
            throw new InvalidArgumentException('Actor reference requires a canonical type and an opaque non-empty id.');
        }
    }
}
