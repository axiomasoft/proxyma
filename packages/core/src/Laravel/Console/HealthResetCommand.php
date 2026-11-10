<?php

declare(strict_types=1);

namespace Proxyma\Laravel\Console;

final class HealthResetCommand extends OperationsCommand
{
    protected const ACTION = 'reset';

    protected $signature = 'proxyma:health-reset {scope} {id} {--reason=} {--expected-revision=} {--idempotency-key=}';

    protected $description = 'Audited revision-fenced reset to unknown in the trusted actor context';
}
