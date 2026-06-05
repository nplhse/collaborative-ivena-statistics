<?php

declare(strict_types=1);

namespace App\Statistics\Application\IndicationDashboard\DTO;

final readonly class IndicationSummaryDeck
{
    /**
     * @param list<IndicationSummarySegment> $genderSegments
     * @param list<IndicationSummarySegment> $urgencySegments
     */
    public function __construct(
        public int $caseCount,
        public array $genderSegments,
        public array $urgencySegments,
    ) {
    }
}
