<?php

declare(strict_types=1);

it('connects only to the marked isolated database', function (): void {
    $db = new PDO('pgsql:host=db;dbname=proxyma_test', 'proxyma_test', 'proxyma-test-only');
    expect($db->query('select current_database()')->fetchColumn())->toBe('proxyma_test');
    expect($db->query('select value from proxyma_rig.marker')->fetchColumn())->toBe('proxyma_test_v1');
});
