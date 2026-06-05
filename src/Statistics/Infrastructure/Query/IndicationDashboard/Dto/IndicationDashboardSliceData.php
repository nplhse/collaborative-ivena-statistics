<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\IndicationDashboard\Dto;

/**
 * Aggregated indication-slice dimensions from a single projection scan.
 */
final readonly class IndicationDashboardSliceData
{
    /**
     * @param list<array{year:int,month:int,count:int}>                $monthlyRows
     * @param array{male:int,female:int,other:int,unknown:int}         $genderCounts
     * @param array<string, int>                                       $ageGroupCounts
     * @param array<string, int>                                       $transportTimeBucketCounts
     * @param list<array{weekday:int,dayTimeBucketCode:int,count:int}> $dayTimeHeatmapCells
     * @param list<array{weekday:int,shiftBucketCode:int,count:int}>   $shiftHeatmapCells
     */
    public function __construct(
        public array $monthlyRows,
        public array $genderCounts,
        public array $ageGroupCounts,
        public array $transportTimeBucketCounts,
        public array $dayTimeHeatmapCells,
        public array $shiftHeatmapCells,
    ) {
    }

    public static function empty(): self
    {
        return new self(
            [],
            ['male' => 0, 'female' => 0, 'other' => 0, 'unknown' => 0],
            [],
            [],
            [],
            [],
        );
    }
}
