<?php

declare(strict_types=1);

namespace Proxyma\Laravel\Console;

final class MetricsCommand extends OperationsCommand
{
    protected const ACTION = 'metrics';

    protected $signature = 'proxyma:metrics {--limit=32}';

    protected $description = 'Read bounded metric samples without tenant or URL labels';
}
