<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Health;

use Illuminate\Database\Connection;
use Packman\Attributes\Internal;
use Proxyma\Infrastructure\Database\PackageSchema;

/** @internal Только внутри catalog transaction после блокировки endpoint/routes/health. */
#[Internal]
final readonly class CredentialHolds
{
    public function __construct(private Connection $db) {}

    public function rotated(string $tenant, string $endpoint, int $oldVersion, int $newVersion): void
    {
        if ($newVersion <= $oldVersion) {
            return;
        }
        PackageSchema::query($this->db, 'route_health')->where('tenant_key', $tenant)
            ->where('credential_endpoint_id', $endpoint)->where('credential_version', $oldVersion)
            ->where('reason_code', 'credential_hold:'.$oldVersion)->update([
                'state' => 'unknown', 'health_epoch' => $this->db->raw('health_epoch + 1'),
                'state_version' => $this->db->raw('state_version + 1'), 'reason_code' => null,
                'open_until' => null, 'probe_token' => null, 'probe_until' => null,
                'credential_endpoint_id' => null, 'credential_version' => null,
            ]);
    }
}
