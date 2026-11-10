<?php

declare(strict_types=1);

namespace Proxyma\Domain\Admission;

use LogicException;

final class LockPosition
{
    private int $tier = 0;

    private string $lastKey = '';

    public function enter(AuthorityLockTier $tier, string $key): void
    {
        if ($tier->value < $this->tier || ($tier->value === $this->tier && strcmp($key, $this->lastKey) <= 0)) {
            throw new LogicException('Authority locks must advance by tier and stable primary key.');
        }
        $this->tier = $tier->value;
        $this->lastKey = $key;
    }
}
