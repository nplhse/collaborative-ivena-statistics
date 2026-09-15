<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\GeographicSegment;

final readonly class GeographicSegmentDistributionGroup
{
    /**
     * @param list<GeographicSegmentDistributionRow> $rows
     */
    public function __construct(
        public string $titleTranslationKey,
        public array $rows,
    ) {
    }
}
