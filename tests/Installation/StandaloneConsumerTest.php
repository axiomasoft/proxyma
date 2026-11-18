<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('независимый Composer consumer выполняет discovery и config cache до migrations', function (string $consumer): void {
    $directory = __DIR__.'/../Fixtures/Consumers/'.$consumer;
    expect(is_file($directory.'/vendor/autoload.php'))->toBeTrue('Install the fixture with Composer before running conformance.');
    $runtime = sys_get_temp_dir().'/pxm_consumer_'.bin2hex(random_bytes(8));
    mkdir($runtime);
    $environment = [
        'APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)),
        'APP_PACKAGES_CACHE' => $runtime.'/packages.php', 'APP_SERVICES_CACHE' => $runtime.'/services.php',
        'APP_CONFIG_CACHE' => $runtime.'/config.php', 'APP_ROUTES_CACHE' => $runtime.'/routes.php', 'APP_EVENTS_CACHE' => $runtime.'/events.php',
        'LARAVEL_STORAGE_PATH' => $runtime.'/storage', 'LOG_CHANNEL' => 'stderr', 'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1', 'DB_DATABASE' => 'proxyma_test', 'DB_USERNAME' => 'proxyma_test',
        'DB_PASSWORD' => 'proxyma-test-only', 'DB_URL' => '',
    ];
    $run = function (array $arguments) use ($directory, $environment): string {
        $process = new Process([PHP_BINARY, '-d', 'zend.assertions=1', '-d', 'assert.exception=1', ...$arguments], $directory, $environment);
        $process->setTimeout(60);
        $process->run();
        expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());

        return $process->getOutput();
    };
    try {
        foreach (['package:discover', 'config:cache', 'route:cache', 'event:cache'] as $command) {
            $run(['artisan', $command, '--no-interaction']);
        }
        $output = $run([dirname($directory).'/probe.php', $directory]);
        $report = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
        expect($report['boot_queries'])->toBe(0)->and($report['missing_schema_reported'])->toBeTrue()
            ->and($report['worker_isolated'])->toBeTrue()->and(strlen($report['registry']))->toBe(64);
        $packages = require $environment['APP_PACKAGES_CACHE'];
        expect($packages)->toHaveKey('axiomasoft/proxyma-core')->not->toHaveKey('axiomasoft/proxyma-filament');
        $lock = json_decode(file_get_contents($directory.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        $names = array_column($lock['packages'], 'name');
        expect($names)->not->toContain('axiomasoft/proxyma-filament')->and(array_filter($names, static fn ($name): bool => str_starts_with($name, 'filament/')))->toBe([]);
    } finally {
        (new Filesystem)->deleteDirectory($runtime);
    }
})->with(['minimal', 'custom']);
