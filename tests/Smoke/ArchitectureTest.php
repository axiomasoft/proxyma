<?php

declare(strict_types=1);

it('keeps core independent of consumer and UI code', function (): void {
    $root = dirname(__DIR__, 2).'/packages/core/src';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $source = file_get_contents($file->getPathname());
            expect($source)->not->toContain('App\\', 'Filament\\', 'ProcessCore\\');
        }
    }
});
