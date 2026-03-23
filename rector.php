<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

$entityPath = __DIR__.'/src/**/Domain/Entity/*';

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withPhpSets(php84: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
    )
    ->withTypeCoverageLevel(0)
    ->withSkip([
        Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector::class => [$entityPath],
        Rector\Php81\Rector\Property\ReadOnlyPropertyRector::class => [$entityPath],
    ]);
