<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

$entityPath = __DIR__.'/src/**/Domain/Entity/*';

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/config',
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withPhpSets(
        php84: true
    )
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    )
    ->withSets([
        Rector\Symfony\Set\SymfonySetList::CONFIGS,
        Rector\Symfony\Set\SymfonySetList::SYMFONY_CODE_QUALITY,
        Rector\Symfony\Set\SymfonySetList::SYMFONY_CONSTRUCTOR_INJECTION,
    ])
    ->withSkip([
        Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector::class => [$entityPath],
        Rector\Php81\Rector\Property\ReadOnlyPropertyRector::class => [$entityPath],
        Rector\TypeDeclaration\Rector\Property\TypedPropertyFromAssignsRector::class => [$entityPath],
    ])
    ->withCache(__DIR__.'/var/cache/rector')
    ->withParallel();
