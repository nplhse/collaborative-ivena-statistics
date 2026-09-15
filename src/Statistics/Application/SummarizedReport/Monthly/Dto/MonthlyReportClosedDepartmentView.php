<?php

declare(strict_types=1);

namespace App\Statistics\Application\SummarizedReport\Monthly\Dto;

final readonly class MonthlyReportClosedDepartmentView
{
    /**
     * @param list<MonthlyReportSegment> $urgencySegments
     */
    public function __construct(
        public int $closedCount,
        public ?float $sharePercent,
        public ?float $closedMomPercent,
        public int $departmentCount,
        public int $totalDepartmentCount,
        public array $urgencySegments,
        public string $detailUrl,
    ) {
    }

    public static function empty(string $detailUrl): self
    {
        return new self(0, null, null, 0, 0, [], $detailUrl);
    }
}
