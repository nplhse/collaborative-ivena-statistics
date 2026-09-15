<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\GeographicSegment;

final readonly class GeographicSegmentOption
{
    public function __construct(
        public GeographicSegment $segment,
        public string $label,
        public bool $translateLabel,
        public int $caseCount,
        public bool $suppressed,
        public bool $showInPicker,
    ) {
    }
}
