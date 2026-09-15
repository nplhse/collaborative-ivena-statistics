<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\GeographicSegment;

final readonly class GeographicSegmentDistributionRow
{
    public function __construct(
        public string $labelTranslationKey,
        public int $count,
        public float $percent,
        public string $barClass = '',
    ) {
    }
}
