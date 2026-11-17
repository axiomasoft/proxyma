<?php

declare(strict_types=1);

if (getenv('PROXYMA_DOCKER_RIG') === '1') {
    require dirname(__DIR__, 2).'/scripts/test-preflight.php';
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
