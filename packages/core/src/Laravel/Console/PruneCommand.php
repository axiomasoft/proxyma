<?php

declare(strict_types=1);

namespace Proxyma\Laravel\Console;

final class PruneCommand extends OperationsCommand
{
    protected const ACTION = 'prune';

    protected $signature = 'proxyma:prune {--limit=32} {--retention=2592000}';

    protected $description = 'Prune one bounded page of safe preauthorization history';
}
