<?php

declare(strict_types=1);

namespace Proxyma\Application\Health;

use DateTimeImmutable;
use Packman\Attributes\Api;
use Proxyma\Domain\Outcomes\RetryAfterParser;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Internal\Database\RowValues;

#[Api(since: '1.0')]
final readonly class CooldownService
{
    public function __construct(private ConnectionScope $scope) {}

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function attribute(array $summary, array $snapshot, DateTimeImmutable $now): array
    {
        $quota = ($summary['status'] ?? null) === 429;
        $interpretation = is_array($snapshot['health_interpretation'] ?? null) ? $snapshot['health_interpretation'] : [];
        $waf = ($summary['status'] ?? null) === 403 && ($interpretation['waf_rate_signal'] ?? false) === true;
        $summary['extends_origin_cooldown'] = $quota;
        $summary['applies_waf_hold'] = $waf;
        $header = is_string($summary['_retry_after'] ?? null) ? $summary['_retry_after'] : null;
        unset($summary['_retry_after']);
        if (! $quota && ! $waf) {
            unset($summary['cooldown_until']);

            return $summary;
        }
        $summary['cooldown_until'] = (new RetryAfterParser)->parse($header, $now)->retryAt->value->format('Y-m-d H:i:s.uP');

        return $summary;
    }

    /**
     * Budget IDs are pinned at authorization and already locked by completion.
     *
     * @param  array<string, mixed>  $summary
     */
    public function extend(array $summary): void
    {
        $cooldownUntil = $summary['cooldown_until'] ?? null;
        if (($summary['extends_origin_cooldown'] ?? false) !== true || ! is_string($cooldownUntil)) {
            return;
        }
        $db = $this->scope->connection;
        $table = PackageSchema::tables()['budgets'];
        $budgetIds = is_array($summary['cooldown_budget_ids'] ?? null) ? RowValues::strList($summary['cooldown_budget_ids']) : [];
        foreach ($budgetIds as $id) {
            $db->update("UPDATE {$table} SET cooldown_until = GREATEST(cooldown_until, ?::timestamptz), revision = revision + 1
                WHERE id = ? AND cooldown_until < ?::timestamptz", [$cooldownUntil, $id, $cooldownUntil]);
        }
    }
}
