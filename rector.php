<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Doctrine\Set\DoctrineSetList;
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
    ->withComposerBased(
        symfony: true,
    )
    ->withSets([
        SymfonySetList::CONFIGS,
        SymfonySetList::SYMFONY_CODE_QUALITY,
        SymfonySetList::SYMFONY_CONSTRUCTOR_INJECTION,
        DoctrineSetList::DOCTRINE_CODE_QUALITY,
        Zenstruck\Foundry\Utils\Rector\FoundrySetList::FOUNDRY_2_7,
    ])
    ->withSkip([
        Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector::class => [$entityPath],
        Rector\Php81\Rector\Property\ReadOnlyPropertyRector::class => [$entityPath],
        Rector\TypeDeclaration\Rector\Property\TypedPropertyFromAssignsRector::class => [$entityPath],
        // PHP-CS-Fixer shortens the FQCN; Rector would otherwise rewrite it on every run.
        Rector\Arguments\Rector\ClassMethod\ReplaceArgumentDefaultValueRector::class => [
            __DIR__.'/src/Analytics/Infrastructure/Http/AnalyticsCookieManager.php',
            __DIR__.'/src/Shared/Infrastructure/Consent/CookieConsentService.php',
            __DIR__.'/src/Shared/Infrastructure/Locale/LocaleCookieManager.php',
        ],
    ])
    ->withCache(__DIR__.'/var/cache/rector')
    ->withParallel();
