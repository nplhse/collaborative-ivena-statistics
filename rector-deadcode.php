<?php

declare(strict_types=1);

/**
 * Dead-Code-Analyse only — nicht in CI oder Apply-Pipeline verwenden.
 * Ausführung: vendor/bin/rector process --config=rector-deadcode.php --dry-run
 */
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withPreparedSets(
        deadCode: true,
    )
    ->withCache(__DIR__.'/var/cache/rector-deadcode')
    ->withParallel();
