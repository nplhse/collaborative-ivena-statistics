<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview\Dto;

final readonly class OverviewTopReportCard
{
    /**
     * @param list<OverviewTopTableRow> $rows
     */
    public function __construct(
        public string $titleTranslationKey,
        public string $labelColumnTranslationKey,
        public string $reportUrl,
        public string $testId,
        public array $rows,
    ) {
    }
}
