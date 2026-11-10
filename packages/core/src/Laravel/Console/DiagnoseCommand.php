<?php

declare(strict_types=1);

namespace Proxyma\Laravel\Console;

final class DiagnoseCommand extends OperationsCommand
{
    protected const ACTION = 'diagnose';

    protected $signature = 'proxyma:diagnose {profile} {target} {--subject-type=operator} {--subject-id=diagnostic} {--traffic=default}';

    protected $description = 'Read-only advisory admission and policy explanation';

    protected $aliases = ['proxyma:explain'];
}
