<?php

declare(strict_types=1);

// Router for the built-in PHP server of the demo environment (make demo-start, ADR 023).
// The built-in server does not pass process variables to $_SERVER, so the demo environment is set here.
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'demo';

// Existing files under public/ are served as they are (never PHP files), everything else goes to Symfony.
$file = __DIR__.'/../public'.parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), \PHP_URL_PATH);
if ('cli-server' === \PHP_SAPI && is_file($file) && !str_ends_with($file, '.php')) {
    return false;
}

require __DIR__.'/../public/index.php';
