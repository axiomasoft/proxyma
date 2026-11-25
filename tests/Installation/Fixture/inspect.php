<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use Filament\Panel;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Proxyma\Application\Diagnostics\Doctor;
use Proxyma\Filament\ProxymaFilamentServiceProvider;
use Proxyma\Filament\ProxymaPlugin;
use Proxyma\Laravel\ProxymaServiceProvider;
use Tests\TestCase;

$installedLoader = require __DIR__.'/vendor/autoload.php';
if (($argv[1] ?? '') === 'autoload-negative') {
    $installedLoader->addPsr4('LeakedWorkspace\\', $argv[2]);
}
$app = require __DIR__.'/bootstrap/app.php';
$queries = 0;
$app->make('events')->listen(QueryExecuted::class, function () use (&$queries): void {
    $queries++;
});
$app->make(Kernel::class)->bootstrap();
$bootQueries = $queries;
$classes = 0;
foreach (['axiomasoft/proxyma-core' => 'Proxyma\\', 'axiomasoft/proxyma-filament' => 'Proxyma\\Filament\\'] as $package => $namespace) {
    if (! InstalledVersions::isInstalled($package)) {
        continue;
    }
    $source = InstalledVersions::getInstallPath($package).'/src/';
    $manifest = json_decode(file_get_contents(dirname($source).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source)) as $file) {
        if ($file->getExtension() !== 'php' || ! preg_match('/^[A-Za-z_]/', $file->getBasename())) {
            continue;
        }
        if (in_array('src/'.substr($file->getPathname(), strlen($source)), $manifest['autoload']['files'] ?? [], true)) {
            continue;
        }
        $class = $namespace.str_replace('/', '\\', substr($file->getPathname(), strlen($source), -4));
        if (! class_exists($class) && ! interface_exists($class) && ! enum_exists($class) && ! trait_exists($class)) {
            throw new RuntimeException('Installed class cannot autoload: '.$class);
        }
        $classes++;
    }
}
$autoloadIsolated = true;
$autoloadEscapes = [];
$insideVendor = static function (string $path): bool {
    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '..') {
            array_pop($parts);

            continue;
        }
        if ($part !== '' && $part !== '.') {
            $parts[] = $part;
        }
    }
    $path = '/'.implode('/', $parts);
    if (! str_starts_with($path, __DIR__.'/vendor/')) {
        return false;
    }
    // Composer сохраняет namespaces для каталогов, исключённых из dist archive.
    while (! file_exists($path)) {
        $path = dirname($path);
    }
    $resolved = realpath($path);

    return $resolved === __DIR__.'/vendor' || str_starts_with($resolved ?: '', __DIR__.'/vendor/');
};
foreach (ClassLoader::getRegisteredLoaders() as $vendor => $loader) {
    $autoloadIsolated = $autoloadIsolated && realpath($vendor) === __DIR__.'/vendor';
    foreach ([...array_values($loader->getPrefixesPsr4()), $loader->getFallbackDirsPsr4(), $loader->getFallbackDirs()] as $paths) {
        foreach ($paths as $path) {
            $autoloadIsolated = $autoloadIsolated && $insideVendor($path);
            if (! $insideVendor($path)) {
                $autoloadEscapes[] = $path;
            }
        }
    }
}
$filament = class_exists(Panel::class);
$pages = [];
if ($filament) {
    $panel = Panel::make()->id('install')->plugin(ProxymaPlugin::make());
    $pages = $panel->getPages();
}
$report = [
    'php' => PHP_VERSION, 'laravel' => $app->version(), 'curl' => curl_version()['version'],
    'ssl' => curl_version()['ssl_version'], 'extensions' => get_loaded_extensions(),
    'boot_queries' => $bootQueries, 'autoload_isolated' => $autoloadIsolated, 'autoloaded_classes' => $classes,
    'autoload_escapes' => $autoloadEscapes,
    'root_test_visible' => class_exists(TestCase::class), 'consumer_app_visible' => class_exists('App\\Models\\User'),
    'core_provider' => $app->getProvider(ProxymaServiceProvider::class) !== null,
    'filament' => $filament, 'pages' => $pages,
    'filament_provider' => $filament && $app->getProvider(ProxymaFilamentServiceProvider::class) !== null,
];
if (($argv[1] ?? '') === 'schema') {
    $db = $app['db']->connection();
    $native = getenv('PROXYMA_INSTALL_NATIVE') === '1';
    $identity = $db->selectOne('SELECT current_database() AS db, current_user AS usr, current_schema() AS schema');
    $expectedUser = $native ? getenv('PROXYMA_INSTALL_DB_USERNAME') : 'proxyma_test';
    if ([$identity->db, $identity->usr, $identity->schema] !== ['proxyma_test', $expectedUser, getenv('PROXYMA_INSTALL_SCHEMA')]) {
        throw new RuntimeException('Unsafe installed fixture database');
    }
    if (! $native && $db->selectOne('SELECT value FROM proxyma_rig.marker')->value !== 'proxyma_test_v1') {
        throw new RuntimeException('Unsafe installed fixture marker');
    }
    $report['postgres'] = $db->selectOne('SHOW server_version')->server_version;
    $report['doctor'] = $app->make(Doctor::class)->inspect();
    $report['migrations'] = $db->getSchemaBuilder()->hasTable('migrations') ? $db->table('migrations')->orderBy('migration')->pluck('migration')->all() : [];
}
echo json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
