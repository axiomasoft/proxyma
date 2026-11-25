<?php

declare(strict_types=1);

namespace Proxyma\Laravel\Console;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final class DoctorCommand extends OperationsCommand
{
    protected const ACTION = 'doctor';

    protected $signature = 'proxyma:doctor';

    protected $description = 'Inspect schema, local transport capabilities and redacted configuration';
}
