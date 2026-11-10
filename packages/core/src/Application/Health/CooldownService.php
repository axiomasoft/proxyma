<?php

declare(strict_types=1);

namespace Proxyma\Application\Health;

use DateTimeImmutable;
use Proxyma\Domain\Outcomes\RetryAfterParser;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Database\PackageSchema;

final readonly class CooldownService
{
    public function __construct(private ConnectionScope $scope) {}

    public function attribute(array $summary, array $snapshot, DateTimeImmutable $now): array
    {
        $quota = ($summary['status'] ?? null) === 429;
        $waf = ($summary['status'] ?? null) === 403 && ($snapshot['health_interpretation']['waf_rate_signal'] ?? false) === true;
        $summary['extends_origin_cooldown'] = $quota;
        $summary['applies_waf_hold'] = $waf;
        $header = $summary['_retry_after'] ?? null;
        unset($summary['_retry_after']);
        if (! $quota && ! $waf) {
            unset($summary['cooldown_until']);

            return $summary;
        }
        $summary['cooldown_until'] = (new RetryAfterParser)->parse($header, $now)->retryAt->value->format('Y-m-d H:i:s.uP');

        return $summary;
    }

    /** Budget IDs are pinned at authorization and already locked by completion. */
    public function extend(array $summary): void
    {
        if (! ($summary['extends_origin_cooldown'] ?? false) || ! isset($summary['cooldown_until'])) {
            return;
        }
        $db = $this->scope->connection;
        $table = PackageSchema::tables()['budgets'];
        foreach ($summary['cooldown_budget_ids'] ?? [] as $id) {
            $db->update("UPDATE {$table} SET cooldown_until = GREATEST(cooldown_until, ?::timestamptz), revision = revision + 1
                WHERE id = ? AND cooldown_until < ?::timestamptz", [$summary['cooldown_until'], $id, $summary['cooldown_until']]);
        }
    }
}
