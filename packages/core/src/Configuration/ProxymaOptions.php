<?php

declare(strict_types=1);

namespace Proxyma\Configuration;

use InvalidArgumentException;
use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Data\SecretRef;
use Packman\Config\Data\TablePrefix;
use Packman\Config\Laravel\ConfigurationValidator;
use Proxyma\Application\Admission\TrafficSharePolicy;
use Proxyma\Domain\Policy\TenantKey;

/**
 * Immutable compiled root configuration snapshot for `axiomasoft/proxyma`.
 */
#[Api(since: '1.0')]
final readonly class ProxymaOptions implements OptionsFactory
{
    /** @var list<string> */
    private const array TOP_KEYS = [
        'connection',
        'table_prefix',
        'default_tenant',
        'settings',
        'safety',
        'transactions',
        'queue',
        'wire',
        'traffic_shares',
        'health',
        'health_cooldown_scopes',
        'scheduler',
        'maintenance',
        'tls',
        'extensions',
        'policy',
    ];

    /**
     * @param  array<string, int>  $trafficShares
     * @param  list<string>  $healthCooldownScopes
     */
    public function __construct(
        public string $connection = 'pgsql',
        public TablePrefix $tablePrefix = new TablePrefix('pxm_'),
        public string $defaultTenant = TenantKey::SYSTEM,
        public SettingsOptions $settings = new SettingsOptions,
        public SafetyOptions $safety = new SafetyOptions,
        public TransactionsOptions $transactions = new TransactionsOptions,
        public QueueOptions $queue = new QueueOptions,
        public SecretRef $wireDigestKey = new SecretRef(''),
        public array $trafficShares = [],
        public HealthOptions $health = new HealthOptions,
        public array $healthCooldownScopes = [],
        public bool $schedulerEnabled = false,
        public MaintenanceOptions $maintenance = new MaintenanceOptions,
        public TlsOptions $tls = new TlsOptions,
        public ExtensionsOptions $extensions = new ExtensionsOptions,
        public PolicyOptions $policy = new PolicyOptions,
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'proxyma'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, self::TOP_KEYS, $path);

        $connection = $config['connection'] ?? 'pgsql';

        if (! is_string($connection) || trim($connection) === '') {
            $validator->add($path.'.connection', 'must be a non-empty database connection name');
            $connection = 'pgsql';
        }

        $tablePrefix = new TablePrefix('pxm_');

        try {
            $tablePrefix = TablePrefix::fromArrayValue($config['table_prefix'] ?? 'pxm_', $path.'.table_prefix');
        } catch (InvalidArgumentException $exception) {
            $validator->add($path.'.table_prefix', $exception->getMessage());
        }

        $defaultTenant = $config['default_tenant'] ?? TenantKey::SYSTEM;

        if (! is_string($defaultTenant) || $defaultTenant === '') {
            $validator->add($path.'.default_tenant', 'must be a non-empty tenant key');
            $defaultTenant = TenantKey::SYSTEM;
        } else {
            try {
                new TenantKey($defaultTenant);
            } catch (InvalidArgumentException $exception) {
                $validator->add($path.'.default_tenant', $exception->getMessage());
            }
        }

        $wireDigestKey = self::readWireDigestKey($validator, $path.'.wire', $config['wire'] ?? []);
        $trafficShares = self::readTrafficShares($validator, $path.'.traffic_shares', $config['traffic_shares'] ?? []);
        $cooldownScopes = self::readCooldownScopes($validator, $path.'.health_cooldown_scopes', $config['health_cooldown_scopes'] ?? []);
        $schedulerEnabled = self::readSchedulerEnabled($validator, $path.'.scheduler', $config['scheduler'] ?? []);

        $rawSettings = self::requireSectionArray($validator, $path.'.settings', $config['settings'] ?? []);
        $rawSafety = self::requireSectionArray($validator, $path.'.safety', $config['safety'] ?? []);
        $rawTransactions = self::requireSectionArray($validator, $path.'.transactions', $config['transactions'] ?? []);
        $rawQueue = self::requireSectionArray($validator, $path.'.queue', $config['queue'] ?? []);
        $rawHealth = self::requireSectionArray($validator, $path.'.health', $config['health'] ?? []);
        $rawMaintenance = self::requireSectionArray($validator, $path.'.maintenance', $config['maintenance'] ?? []);
        $rawTls = self::requireSectionArray($validator, $path.'.tls', $config['tls'] ?? []);
        $rawExtensions = self::requireSectionArray($validator, $path.'.extensions', $config['extensions'] ?? []);
        $rawPolicy = self::requireSectionArray($validator, $path.'.policy', $config['policy'] ?? []);

        $validator->failIfAny();

        return new self(
            connection: $connection,
            tablePrefix: $tablePrefix,
            defaultTenant: $defaultTenant,
            settings: SettingsOptions::fromArray($rawSettings, $path.'.settings'),
            safety: SafetyOptions::fromArray($rawSafety, $path.'.safety'),
            transactions: TransactionsOptions::fromArray($rawTransactions, $path.'.transactions'),
            queue: QueueOptions::fromArray($rawQueue, $path.'.queue'),
            wireDigestKey: $wireDigestKey,
            trafficShares: $trafficShares,
            health: HealthOptions::fromArray($rawHealth, $path.'.health'),
            healthCooldownScopes: $cooldownScopes,
            schedulerEnabled: $schedulerEnabled,
            maintenance: MaintenanceOptions::fromArray($rawMaintenance, $path.'.maintenance'),
            tls: TlsOptions::fromArray($rawTls, $path.'.tls'),
            extensions: ExtensionsOptions::fromArray($rawExtensions, $path.'.extensions'),
            policy: PolicyOptions::fromArray($rawPolicy, $path.'.policy'),
        );
    }

    /** @return array<array-key, mixed> */
    private static function requireSectionArray(ConfigurationValidator $validator, string $path, mixed $value): array
    {
        if (! is_array($value)) {
            $validator->add($path, 'must be an array');

            return [];
        }

        return $value;
    }

    private static function readWireDigestKey(ConfigurationValidator $validator, string $path, mixed $wire): SecretRef
    {
        if (! is_array($wire)) {
            $validator->add($path, 'must be an array');

            return new SecretRef('');
        }

        $validator->rejectUnknownKeys($wire, ['digest_key'], $path);
        $rawKey = $wire['digest_key'] ?? '';

        if (! is_string($rawKey)) {
            $validator->add($path.'.digest_key', 'must be a string');

            return new SecretRef('');
        }

        return new SecretRef($rawKey);
    }

    /** @return array<string, int> */
    private static function readTrafficShares(ConfigurationValidator $validator, string $path, mixed $value): array
    {
        if (! is_array($value)) {
            $validator->add($path, 'must be an associative map of traffic class names to integer percentages');

            return [];
        }

        try {
            return (new TrafficSharePolicy($value))->classes;
        } catch (InvalidArgumentException $exception) {
            $validator->add($path, $exception->getMessage());

            return [];
        }
    }

    /** @return list<string> */
    private static function readCooldownScopes(ConfigurationValidator $validator, string $path, mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $validator->add($path, 'must be a list of "route-origin" or "egress-origin" scope identifiers');

            return [];
        }

        $scopes = [];

        foreach ($value as $scope) {
            if ($scope !== 'route-origin' && $scope !== 'egress-origin') {
                $validator->add($path, 'supports only "route-origin" and "egress-origin"');

                return [];
            }

            $scopes[] = $scope;
        }

        return $scopes;
    }

    private static function readSchedulerEnabled(ConfigurationValidator $validator, string $path, mixed $scheduler): bool
    {
        if (! is_array($scheduler)) {
            $validator->add($path, 'must be an array');

            return false;
        }

        $validator->rejectUnknownKeys($scheduler, ['enabled'], $path);
        $enabled = $scheduler['enabled'] ?? false;

        if (! is_bool($enabled)) {
            $validator->add($path.'.enabled', 'must be a boolean');

            return false;
        }

        return $enabled;
    }
}
