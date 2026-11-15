<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';

new Dotenv()->bootEnv(__DIR__.'/../.env', 'test');

// PHPStan analyses tests/ as well: the test environment also maps the entities of test-only bundles (ADR 037,
// tests/Fixtures/Plugin); its mapping of the application's entities is the same as in every other environment.
$kernel = new Kernel('test', (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
