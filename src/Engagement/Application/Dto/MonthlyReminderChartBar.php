<?php

declare(strict_types=1);

namespace App\Engagement\Application\Dto;

final readonly class MonthlyReminderChartBar
{
    public function __construct(
        public string $label,
        public int $allocationCount,
        public int $barHeightPx,
        public bool $isReportingMonth,
    ) {
    }
}
