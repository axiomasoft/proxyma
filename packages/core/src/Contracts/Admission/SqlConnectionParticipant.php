<?php

declare(strict_types=1);

namespace Proxyma\Contracts\Admission;

use Illuminate\Database\Connection;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Local SQL adapter participating in the caller's atomic authority transaction. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface SqlConnectionParticipant
{
    public function connection(): Connection;
}
