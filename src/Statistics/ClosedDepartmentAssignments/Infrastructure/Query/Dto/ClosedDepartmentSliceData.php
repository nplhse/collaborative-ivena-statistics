<?php

declare(strict_types=1);

namespace App\Statistics\ClosedDepartmentAssignments\Infrastructure\Query\Dto;

final readonly class ClosedDepartmentSliceData
{
    /**
     * @param list<array{year: int, month: int, day?: int, closed: int, total: int}> $timeSeriesRows
     * @param list<array{id: ?int, name: string, closed: int, total: int}>           $departments
     * @param list<array{id: ?int, name: string, closed: int, total: int}>           $specialities
     * @param list<array{id: ?int, name: string, closed: int, total: int}>           $indications
     * @param list<array{id: ?int, name: string, closed: int, total: int}>           $occasions
     * @param list<array{id: ?int, name: string, closed: int, total: int}>           $assignments
     * @param list<array{id: ?int, name: string, closed: int, total: int}>           $infections
     * @param list<array{id: ?int, name: string, closed: int, total: int}>           $dispatchAreas
     * @param array<string, int>                                                     $closedTransportBuckets
     * @param array<string, int>                                                     $regularTransportBuckets
     * @param list<array{weekday: int, twoHourSlot: int, count: int}>                $weekdayDayTimeCells
     */
    public function __construct(
        public array $timeSeriesRows,
        public array $departments,
        public array $specialities,
        public array $indications,
        public array $occasions,
        public array $assignments,
        public array $infections,
        public array $dispatchAreas,
        public array $closedTransportBuckets,
        public array $regularTransportBuckets,
        public array $weekdayDayTimeCells,
    ) {
    }

    public static function empty(): self
    {
        return new self([], [], [], [], [], [], [], [], [], [], []);
    }
}
