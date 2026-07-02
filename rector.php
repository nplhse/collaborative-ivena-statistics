<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Doctrine\Set\DoctrineSetList;
use Rector\Symfony\CodeQuality\Rector\Class_\ControllerMethodInjectionToConstructorRector;
use Rector\Symfony\Set\SymfonySetList;

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
        codeQuality: true,
        typeDeclarations: true,
    )
    ->withSets([
        SymfonySetList::CONFIGS,
        SymfonySetList::SYMFONY_CODE_QUALITY,
        SymfonySetList::SYMFONY_CONSTRUCTOR_INJECTION,
        SymfonySetList::SYMFONY_80,
        SymfonySetList::SYMFONY_81,
        DoctrineSetList::DOCTRINE_CODE_QUALITY,
        Zenstruck\Foundry\Utils\Rector\FoundrySetList::FOUNDRY_2_7,
    ])
    ->withSkip([
        Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector::class => [$entityPath],
        Rector\Php81\Rector\Property\ReadOnlyPropertyRector::class => [$entityPath],
        Rector\TypeDeclaration\Rector\Property\TypedPropertyFromAssignsRector::class => [$entityPath],
        ControllerMethodInjectionToConstructorRector::class => [
            __DIR__.'/src/Admin/UI/Http/Controller/Hospital/HospitalCrudController.php',
        ],
    ])
    ->withCache(__DIR__.'/var/cache/rector')
    ->withParallel();
