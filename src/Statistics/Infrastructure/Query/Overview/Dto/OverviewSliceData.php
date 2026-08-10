<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\Overview\Dto;

final readonly class OverviewSliceData
{
    /**
     * @param list<array{year:int,month:int,count:int}>                $monthlyRows
     * @param array<string, int>                                       $ageGroupCounts
     * @param array<string, int>                                       $transportTimeBucketCounts
     * @param array<array-key, int>                                    $transportTypeBucketCounts keyed by projection code as string
     * @param list<array{weekday:int,dayTimeBucketCode:int,count:int}> $dayTimeHeatmapCells
     * @param list<array{weekday:int,shiftBucketCode:int,count:int}>   $shiftHeatmapCells
     */
    public function __construct(
        public array $monthlyRows,
        public array $ageGroupCounts,
        public array $transportTimeBucketCounts,
        public array $transportTypeBucketCounts,
        public array $dayTimeHeatmapCells,
        public array $shiftHeatmapCells,
    ) {
    }

    public static function empty(): self
    {
        return new self([], [], [], [], [], []);
    }
}
