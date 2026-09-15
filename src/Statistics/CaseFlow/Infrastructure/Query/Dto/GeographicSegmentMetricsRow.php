<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Infrastructure\Query\Dto;

final readonly class GeographicSegmentMetricsRow
{
    public function __construct(
        public int $populationCases,
        public int $segmentCases,
        public ?float $medianTransportMinutes,
    ) {
    }
}
