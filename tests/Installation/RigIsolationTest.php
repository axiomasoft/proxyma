<?php

declare(strict_types=1);

it('connects only to the marked isolated database', function (): void {
    if (getenv('PROXYMA_INSTALL_NATIVE') === '1') {
        $db = new PDO('pgsql:host='.getenv('PGSQL_HOST').';port='.getenv('PGSQL_PORT').';dbname=proxyma_test', getenv('PGSQL_USERNAME'), getenv('PGSQL_PASSWORD'));
        expect($db->query('select current_database(), current_user')->fetch(PDO::FETCH_NUM))->toBe(['proxyma_test', getenv('PGSQL_USERNAME')]);

        return;
    }

    $db = new PDO('pgsql:host=db;dbname=proxyma_test', 'proxyma_test', 'proxyma-test-only');
    expect($db->query('select current_database()')->fetchColumn())->toBe('proxyma_test');
    expect($db->query('select value from proxyma_rig.marker')->fetchColumn())->toBe('proxyma_test_v1');
});
