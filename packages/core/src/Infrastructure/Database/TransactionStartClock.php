<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Database;

use Illuminate\Database\Connection;
use LogicException;
use WeakMap;

/** One sampler per connection; no request scopes are retained by the connection hook. */
final class TransactionStartClock
{
    /** @var WeakMap<Connection, self>|null */
    private static ?WeakMap $clocks = null;

    private ?int $sample = null;

    public static function forConnection(Connection $connection): self
    {
        self::$clocks ??= new WeakMap;
        if (! isset(self::$clocks[$connection])) {
            $clock = new self;
            self::$clocks[$connection] = $clock;
            $connection->beforeStartingTransaction(static function (Connection $connection) use ($clock): void {
                if ($connection->transactionLevel() === 0) {
                    $clock->sample = hrtime(true);
                }
            });
        }

        return self::$clocks[$connection];
    }

    public function beforeRoot(): int
    {
        return $this->sample ?? throw new LogicException('Resolve ConnectionScope before starting the owning transaction to sample the monotonic clock.');
    }
}
