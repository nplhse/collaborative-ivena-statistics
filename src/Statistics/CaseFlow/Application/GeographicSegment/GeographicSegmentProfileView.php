<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\GeographicSegment;

final readonly class GeographicSegmentProfileView
{
    /**
     * @param list<GeographicSegmentDistributionGroup> $groups
     */
    public function __construct(
        public ?GeographicSegment $segment,
        public ?string $label,
        public bool $translateLabel,
        public bool $hasSelection,
        public bool $suppressed,
        public int $segmentCases,
        public int $populationCases,
        public ?float $sharePercent,
        public ?float $medianTransportMinutes,
        public bool $showMedianTransport,
        public GeographicSegmentProfileDimension $dimension,
        public array $groups,
    ) {
    }

    public static function empty(GeographicSegmentProfileDimension $dimension): self
    {
        return new self(
            null,
            null,
            false,
            false,
            false,
            0,
            0,
            null,
            null,
            false,
            $dimension,
            [],
        );
    }
}
