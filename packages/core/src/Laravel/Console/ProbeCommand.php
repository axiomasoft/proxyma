<?php

declare(strict_types=1);

namespace Proxyma\Laravel\Console;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final class ProbeCommand extends OperationsCommand
{
    protected const ACTION = 'probe';

    protected $signature = 'proxyma:probe {profile} {route} {target} {--subject-type=operator} {--subject-id=probe} {--seconds=30}';

    protected $description = 'Execute one HEAD probe using ordinary admission and pinned scopes';
}
