<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO;

/**
 * Navigation link from a StatisticWidget: translation key for label + route + query merge.
 *
 * @phpstan-type Params array<string, scalar|list<scalar>|null>
 * @phpstan-type RemoveKeys list<string>
 */
final readonly class StatisticWidgetNavigationTarget
{
    /**
     * @param Params     $params
     * @param RemoveKeys $removeKeys Query keys to strip before merge (e.g. report, limit)
     */
    public function __construct(
        public string $labelTranslationKey,
        public string $route,
        public array $params = [],
        public array $removeKeys = [],
    ) {
    }
}
