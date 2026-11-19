<?php

declare(strict_types=1);

namespace Proxyma\Laravel;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\ConnectionEstablished;
use LogicException;
use Packman\Attributes\Internal;
use Packman\Config\Data\TablePrefix;
use Packman\Config\Exceptions\InvalidConfiguration;
use Proxyma\Configuration\ProxymaOptions;
use WeakMap;
use WeakReference;

#[Internal]
final class TablePrefixResolver
{
    /** @var WeakMap<Connection, WeakReference<ContainerContract>>|null */
    private static ?WeakMap $owners = null;

    public static function register(Application $app): void
    {
        self::$owners ??= new WeakMap;
        $owners = self::$owners;
        $owner = WeakReference::create($app);
        $app->make(Dispatcher::class)->listen(ConnectionEstablished::class, static function (ConnectionEstablished $event) use ($owners, $owner): void {
            $owners[$event->connection] = $owner;
        });

        if ($app->resolved('db')) {
            foreach ($app->make(DatabaseManager::class)->getConnections() as $connection) {
                $owners[$connection] = $owner;
            }
        }
    }

    public static function forConnection(?Connection $connection): TablePrefix
    {
        if ($connection === null) {
            return self::forApplication(Container::getInstance());
        }

        $owner = self::$owners[$connection] ?? null;
        if ($owner === null) {
            return new TablePrefix('pxm_');
        }

        return self::forApplication($owner->get() ?? throw new LogicException('The application owning this Proxyma connection has been released.'));
    }

    public static function forApplication(ContainerContract $app): TablePrefix
    {
        if ($app->resolved(ProxymaOptions::class)) {
            $options = $app->factory(ProxymaOptions::class);
            try {
                return $options()->tablePrefix;
            } catch (InvalidConfiguration) {
                // Diagnostics can still inspect the schema when another option is invalid.
            }
        }

        $value = $app->bound(Repository::class) ? $app->make(Repository::class)->get('proxyma.table_prefix', 'pxm_') : 'pxm_';

        return TablePrefix::fromArrayValue($value, 'proxyma.table_prefix');
    }
}
