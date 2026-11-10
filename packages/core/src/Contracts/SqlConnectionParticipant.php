<?php

declare(strict_types=1);

namespace Proxyma\Contracts;

use Illuminate\Database\Connection;

/** Local SQL adapter participating in the caller's atomic authority transaction. */
interface SqlConnectionParticipant
{
    public function connection(): Connection;
}
