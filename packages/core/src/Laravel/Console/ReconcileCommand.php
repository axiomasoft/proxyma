<?php

declare(strict_types=1);

namespace Proxyma\Laravel\Console;

final class ReconcileCommand extends OperationsCommand
{
    protected const ACTION = 'reconcile';

    protected $signature = 'proxyma:reconcile {--limit=32 : Maximum operation roots, 1..64}';

    protected $description = 'Reconcile one bounded page in the trusted tenant context without dispatching requests';
}
