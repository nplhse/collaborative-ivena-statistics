<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\Application;

use App\Statistics\Application\Mapping\AllocationStatsDayTimeBucketProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsShiftBucketProjectionCode;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkHeatmapData;
use App\Statistics\Benchmarking\Infrastructure\Query\Dto\BenchmarkAggregationResult;
use App\Statistics\Benchmarking\Infrastructure\Query\Dto\BenchmarkDistributionRow;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class BenchmarkHeatmapBuilder
{
    private const string DAY_TIME_HEATMAP_DIMENSION = 'day_time_heatmap';

    private const string SHIFT_HEATMAP_DIMENSION = 'shift_heatmap';

    /** @var list<int> */
    private const array ISO_WEEKDAYS = [1, 2, 3, 4, 5, 6, 7];

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function buildDayTimeCaseDistribution(BenchmarkAggregationResult $result): BenchmarkHeatmapData
    {
        return $this->buildDeltaHeatmap(
            $result,
            self::DAY_TIME_HEATMAP_DIMENSION,
            array_map(
                static fn (AllocationStatsDayTimeBucketProjectionCode $case): int => $case->value,
                AllocationStatsDayTimeBucketProjectionCode::displayOrder(),
            ),
            static fn (int $code): string => AllocationStatsDayTimeBucketProjectionCode::from($code)->labelTranslationKey(),
        );
    }

    public function buildShiftCaseDistribution(BenchmarkAggregationResult $result): BenchmarkHeatmapData
    {
        return $this->buildDeltaHeatmap(
            $result,
            self::SHIFT_HEATMAP_DIMENSION,
            array_map(
                static fn (AllocationStatsShiftBucketProjectionCode $case): int => $case->value,
                AllocationStatsShiftBucketProjectionCode::displayOrder(),
            ),
            static fn (int $code): string => AllocationStatsShiftBucketProjectionCode::from($code)->labelTranslationKey(),
        );
    }

    /**
     * @param list<int>             $bucketCodes
     * @param \Closure(int): string $labelTranslationKey
     */
    private function buildDeltaHeatmap(
        BenchmarkAggregationResult $result,
        string $dimension,
        array $bucketCodes,
        \Closure $labelTranslationKey,
    ): BenchmarkHeatmapData {
        $cells = $this->cellsByWeekdayAndBucket($result, $dimension);
        $columnLabels = array_map(
            fn (int $code): string => $this->translator->trans($labelTranslationKey($code), [], 'statistics'),
            $bucketCodes,
        );

        $rowLabels = [];
        $deltaMatrix = [];
        $primaryShareMatrix = [];
        $comparisonShareMatrix = [];
        $maxAbsDelta = 0.0;

        foreach (self::ISO_WEEKDAYS as $weekday) {
            $rowLabels[] = $this->translator->trans('stats.benchmark.weekday.'.$this->weekdayKey($weekday), [], 'statistics');
            $deltaRow = [];
            $primaryRow = [];
            $comparisonRow = [];

            foreach ($bucketCodes as $bucketCode) {
                $cell = $cells[$weekday][$bucketCode] ?? null;
                $primaryCount = null !== $cell ? $cell->primaryCount : 0;
                $comparisonCount = null !== $cell ? $cell->comparisonCount : 0;
                $primaryShare = $this->share($primaryCount, $result->primary->total);
                $comparisonShare = $this->share($comparisonCount, $result->comparison->total);
                $delta = round($primaryShare - $comparisonShare, 1);

                $deltaRow[] = $delta;
                $primaryRow[] = $primaryShare;
                $comparisonRow[] = $comparisonShare;
                $maxAbsDelta = max($maxAbsDelta, abs($delta));
            }

            $deltaMatrix[] = $deltaRow;
            $primaryShareMatrix[] = $primaryRow;
            $comparisonShareMatrix[] = $comparisonRow;
        }

        return new BenchmarkHeatmapData(
            $rowLabels,
            $columnLabels,
            $deltaMatrix,
            $primaryShareMatrix,
            $comparisonShareMatrix,
            $maxAbsDelta,
        );
    }

    /**
     * @return array<int, array<int, BenchmarkDistributionRow>>
     */
    private function cellsByWeekdayAndBucket(BenchmarkAggregationResult $result, string $dimension): array
    {
        $cells = [];

        foreach ($result->distributionRows as $row) {
            if ($row->dimension !== $dimension || null === $row->bucketLabel || '' === $row->bucketLabel) {
                continue;
            }

            $weekday = (int) $row->bucketKey;
            $bucketCode = (int) $row->bucketLabel;
            $cells[$weekday][$bucketCode] = $row;
        }

        return $cells;
    }

    private function share(int $numerator, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round(((float) $numerator / (float) $total) * 100.0, 1);
    }

    private function weekdayKey(int $weekday): string
    {
        return match ($weekday) {
            1 => 'monday',
            2 => 'tuesday',
            3 => 'wednesday',
            4 => 'thursday',
            5 => 'friday',
            6 => 'saturday',
            7 => 'sunday',
            default => (string) $weekday,
        };
    }
}
