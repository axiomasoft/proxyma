<?php

declare(strict_types=1);

use Tests\TestCase;

pest()->extend(TestCase::class)->group('docker-rig')->in('Installation');
pest()->extend(TestCase::class)->in('Smoke');
