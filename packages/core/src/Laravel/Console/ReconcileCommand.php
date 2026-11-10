<?php

declare(strict_types=1);

namespace Proxyma\Laravel\Console;

use Illuminate\Console\Command;
use Proxyma\Application\Recovery\Reconciler;
use Proxyma\Contracts\TenantContextResolver;

final class ReconcileCommand extends Command
{
    protected $signature = 'proxyma:reconcile {--limit=32 : Maximum operation roots, 1..64}';

    protected $description = 'Reconcile one bounded page in the trusted tenant context without dispatching requests';

    public function handle(Reconciler $reconciler, TenantContextResolver $tenants): int
    {
        $value = (string) $this->option('limit');
        if (! ctype_digit($value) || (int) $value < 1 || (int) $value > 64) {
            $this->error('limit must be 1..64');

            return self::INVALID;
        }
        $page = $reconciler->run($tenants->resolve()->tenant, (int) $value);
        $this->line(json_encode($page, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
