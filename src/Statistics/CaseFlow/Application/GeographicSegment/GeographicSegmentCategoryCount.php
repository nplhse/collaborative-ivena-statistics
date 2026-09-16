<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\GeographicSegment;

final readonly class GeographicSegmentCategoryCount
{
    public function __construct(
        public int $segmentCount,
        public int $referenceCount,
    ) {
    }

    public static function empty(): self
    {
        return new self(0, 0);
    }
}
