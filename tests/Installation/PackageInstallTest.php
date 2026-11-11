<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('скопированная поставка загружается без monorepo и выдерживает fresh, repeat и explicit provider', function (string $lane): void {
    $root = dirname(__DIR__, 2);
    $runtime = '/tmp/pxm_install_'.bin2hex(random_bytes(8));
    $filament = str_starts_with($lane, 'filament');
    $schema = 'pxm_install_'.bin2hex(random_bytes(8));
    $log = $root.'/build/installation-'.$lane.'.log';
    file_put_contents($log, 'lane='.$lane.PHP_EOL);
    $environment = [
        'APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)),
        'APP_PACKAGES_CACHE' => $runtime.'/bootstrap/cache/packages.php',
        'APP_SERVICES_CACHE' => $runtime.'/bootstrap/cache/services.php',
        'APP_CONFIG_CACHE' => $runtime.'/bootstrap/cache/config.php',
        'APP_ROUTES_CACHE' => $runtime.'/bootstrap/cache/routes.php',
        'APP_EVENTS_CACHE' => $runtime.'/bootstrap/cache/events.php',
        'LARAVEL_STORAGE_PATH' => $runtime.'/storage', 'LOG_CHANNEL' => 'stderr',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        'PROXYMA_INSTALL_SCHEMA' => $schema, 'PROXYMA_INSTALL_FILAMENT' => $filament ? '1' : '0',
        'PROXYMA_EXPLICIT_PROVIDERS' => '0', 'COMPOSER_VENDOR_DIR' => false,
        'PROXYMA_INSTALL_DB_PORT' => '1',
        'COMPOSER_DISABLE_NETWORK' => '1',
    ];
    $run = function (array $command, int $exit = 0) use ($runtime, $log, &$environment): string {
        if ($command[0] === PHP_BINARY) {
            array_splice($command, 1, 0, ['-d', 'display_errors=stderr']);
        }
        $process = new Process($command, is_dir($runtime) ? $runtime : dirname(__DIR__, 2), $environment);
        $process->setTimeout(180);
        $process->run();
        file_put_contents($log, $process->getCommandLine().PHP_EOL.'exit='.$process->getExitCode().PHP_EOL.$process->getOutput().$process->getErrorOutput(), FILE_APPEND);
        expect($process->getExitCode())->toBe($exit, $process->getOutput().$process->getErrorOutput());

        return $process->getOutput();
    };
    $artisan = fn (string $command, int $exit = 0): string => $run([PHP_BINARY, 'artisan', $command, '--no-interaction'], $exit);
    $inspect = fn (string $mode = ''): array => json_decode($run([PHP_BINARY, 'inspect.php', $mode, $root.'/tests']), true, flags: JSON_THROW_ON_ERROR);
    $cache = function () use ($artisan): void {
        foreach (['package:discover', 'config:cache', 'route:cache', 'event:cache'] as $command) {
            $artisan($command);
        }
    };
    $db = new PDO('pgsql:host=db;dbname=proxyma_test', 'proxyma_test', 'proxyma-test-only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    expect($db->query('SELECT current_database(), current_user, (SELECT value FROM proxyma_rig.marker)')->fetch(PDO::FETCH_NUM))
        ->toBe(['proxyma_test', 'proxyma_test', 'proxyma_test_v1']);
    $snapshot = function () use ($db, $schema): array {
        $sql = <<<'SQL'
SELECT 'column' AS kind, table_name AS object, column_name || ':' || data_type || ':' || udt_name || ':' || is_nullable || ':' || coalesce(column_default, '') AS definition
FROM information_schema.columns WHERE table_schema = ?
UNION ALL SELECT 'constraint', c.relname, pg_get_constraintdef(p.oid) FROM pg_constraint p JOIN pg_class c ON c.oid=p.conrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname = ?
UNION ALL SELECT 'trigger', c.relname, pg_get_triggerdef(t.oid) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname = ? AND NOT t.tgisinternal
UNION ALL SELECT 'index', tablename, indexdef FROM pg_indexes WHERE schemaname = ?
ORDER BY kind, object, definition
SQL;
        $query = $db->prepare($sql);
        $query->execute([$schema, $schema, $schema, $schema]);

        return $query->fetchAll(PDO::FETCH_ASSOC);
    };
    try {
        expect(is_file($root.'/build/installation/composer.phar'))->toBeTrue('Run python3 scripts/install-fixture.py prepare before install tests.');
        $run(['python3', $root.'/scripts/install-fixture.py', 'install', '--lane', $lane, '--destination', $runtime]);
        expect(is_link($runtime.'/vendor/axiomasoft/proxyma'))->toBeFalse()
            ->and(is_dir($runtime.'/sources'))->toBeFalse();
        $lock = json_decode(file_get_contents($runtime.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        $packages = array_column($lock['packages'], 'version', 'name');
        expect($lock['packages-dev'])->toBe([])->and($packages)->not->toHaveKey('orchestra/testbench');
        foreach ($filament ? ['core', 'filament'] : ['core'] as $package) {
            expect(json_decode(file_get_contents($runtime.'/vendor/axiomasoft/'.($package === 'core' ? 'proxyma' : 'proxyma-filament').'/composer.json'), true, flags: JSON_THROW_ON_ERROR))
                ->toBe(json_decode(file_get_contents($root.'/packages/'.$package.'/composer.json'), true, flags: JSON_THROW_ON_ERROR));
        }
        if (! $filament) {
            expect(array_filter(array_keys($packages), fn (string $name): bool => str_starts_with($name, 'filament/')))->toBe([]);
        }
        $cache();
        $boot = $inspect();
        expect($boot['php'])->toStartWith('8.4.')->and($boot['boot_queries'])->toBe(0)
            ->and($boot['autoload_isolated'])->toBeTrue(json_encode($boot['autoload_escapes']))->and($boot['autoloaded_classes'])->toBeGreaterThan(100)
            ->and($boot['root_test_visible'])->toBeFalse()->and($boot['consumer_app_visible'])->toBeFalse()
            ->and($boot['core_provider'])->toBeTrue()->and($boot['filament'])->toBe($filament)
            ->and($boot['filament_provider'])->toBe($filament)->and($boot['laravel'])->toBe(ltrim($packages['laravel/framework'], 'v'));
        foreach (['curl', 'mbstring', 'openssl', 'pdo_pgsql'] as $extension) {
            expect($boot['extensions'])->toContain($extension);
        }
        expect($boot['ssl'])->toContain('OpenSSL')->and($boot['curl'])->toBe('7.88.1');
        if ($filament) {
            expect($boot['pages'])->toHaveCount(9)->and(is_link($runtime.'/vendor/axiomasoft/proxyma-filament'))->toBeFalse();
        }
        expect($inspect('autoload-negative')['autoload_isolated'])->toBeFalse();
        $manifest = require $environment['APP_PACKAGES_CACHE'];
        expect($manifest)->toHaveKey('axiomasoft/proxyma');
        $artisan('config:clear');
        $environment['PROXYMA_INSTALL_DB_PORT'] = '5432';
        $cache();
        $previous = null;
        for ($fresh = 0; $fresh < 2; $fresh++) {
            $db->exec('CREATE SCHEMA '.$schema);
            $before = $inspect('schema');
            expect($before['doctor']['schema']['healthy'])->toBeFalse()
                ->and($before['doctor']['schema']['missingTables'])->toContain('pxm_routes');
            $artisan('proxyma:doctor', 1);
            $artisan('migrate');
            $after = $inspect('schema');
            expect($after['postgres'])->toStartWith('17.')->and($after['doctor']['healthy'])->toBeTrue()
                ->and($after['doctor']['network_io'])->toBeFalse()->and($after['migrations'])->toHaveCount(15);
            $state = $snapshot();
            expect($state)->not->toBe([]);
            $artisan('migrate');
            expect($inspect('schema')['migrations'])->toBe($after['migrations'])->and($snapshot())->toBe($state);
            $cache();
            $artisan('proxyma:doctor');
            if ($previous !== null) {
                expect($state)->toBe($previous);
            }
            $previous = $state;
            $db->exec('ALTER TABLE '.$schema.'.pxm_policy_heads DROP CONSTRAINT pxm_policy_current_fk');
            $mismatch = json_decode($artisan('proxyma:doctor', 1), true, flags: JSON_THROW_ON_ERROR);
            expect($mismatch['healthy'])->toBeFalse()->and($mismatch['schema']['missingConstraints'])->toContain('pxm_policy_current_fk');
            $db->exec('DROP SCHEMA '.$schema.' CASCADE');
        }
        $artisan('config:clear');
        $composer = json_decode(file_get_contents($runtime.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $composer['extra']['laravel']['dont-discover'] = ['axiomasoft/proxyma', 'axiomasoft/proxyma-filament'];
        file_put_contents($runtime.'/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
        unlink($environment['APP_PACKAGES_CACHE']);
        unlink($environment['APP_SERVICES_CACHE']);
        $artisan('package:discover');
        $manifest = require $environment['APP_PACKAGES_CACHE'];
        expect($manifest)->not->toHaveKey('axiomasoft/proxyma')->not->toHaveKey('axiomasoft/proxyma-filament');
        $disabled = $inspect();
        expect($disabled['core_provider'])->toBeFalse()->and($disabled['filament_provider'])->toBeFalse();
        $environment['PROXYMA_EXPLICIT_PROVIDERS'] = '1';
        $cache();
        $explicit = $inspect();
        expect($explicit['core_provider'])->toBeTrue()->and($explicit['filament_provider'])->toBe($filament)
            ->and($explicit['boot_queries'])->toBe(0);
        $db->exec('CREATE SCHEMA '.$schema);
        $artisan('migrate');
        expect($inspect('schema')['doctor']['healthy'])->toBeTrue();
        $artisan('migrate');
        $cache();
        $artisan('proxyma:doctor');
    } finally {
        $db->exec('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');
        (new Filesystem)->deleteDirectory($runtime);
    }
})->with(['core', 'filament', 'core-lowest', 'filament-lowest']);

it('installer отвергает destination вне своего временного каталога до записи', function (): void {
    $process = new Process(['python3', 'scripts/install-fixture.py', 'install', '--lane', 'core', '--destination', '/work'], dirname(__DIR__, 2));
    $process->run();
    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('install requires --lane and a new /tmp/pxm_install_* directory');
});
