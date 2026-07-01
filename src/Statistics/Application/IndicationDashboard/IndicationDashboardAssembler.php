<?php

declare(strict_types=1);

namespace App\Statistics\Application\IndicationDashboard;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Statistics\Application\IndicationDashboard\DTO\IndicationChartSeries;
use App\Statistics\Application\IndicationDashboard\DTO\IndicationDistributionRow;
use App\Statistics\Application\IndicationDashboard\DTO\IndicationHeatmapData;
use App\Statistics\Application\IndicationDashboard\DTO\IndicationSummaryDeck;
use App\Statistics\Application\IndicationDashboard\DTO\IndicationSummarySegment;
use App\Statistics\Application\Mapping\AllocationStatsDayTimeBucketProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsGenderProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsShiftBucketProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsUrgencyProjectionCode;
use App\Statistics\Application\Mapping\StatisticsAgeGroupBucketSql;
use App\Statistics\Infrastructure\Query\IndicationDashboard\Dto\IndicationDashboardMetricsRow;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class IndicationDashboardAssembler
{
    /** @var list<string> */
    private const array TRANSPORT_TIME_BUCKET_KEYS = [
        'under_10',
        '10_20',
        '20_30',
        '30_40',
        '40_50',
        '50_60',
        'over_60',
    ];

    /** @var list<int> */
    private const array ISO_WEEKDAYS = [1, 2, 3, 4, 5, 6, 7];

    /** @var array<string, string> */
    private const array GENDER_BAR_CLASSES = [
        'M' => 'bg-primary',
        'F' => 'bg-pink',
        'X' => 'bg-purple',
    ];

    /** @var array<int, string> */
    private const array URGENCY_BAR_CLASSES = [
        1 => 'bg-red',
        2 => 'bg-yellow',
        3 => 'bg-green',
    ];

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<array{year:int,month:int,count:int}> $monthlyRows
     */
    public function buildTimeSeries(array $monthlyRows): IndicationChartSeries
    {
        $labels = [];
        $values = [];

        foreach ($monthlyRows as $row) {
            $labels[] = sprintf('%04d-%02d', $row['year'], $row['month']);
            $values[] = $row['count'];
        }

        return new IndicationChartSeries($labels, $values);
    }

    /**
     * @param list<array{age:int,count:int}> $histogramRows
     */
    public function buildAgeHistogram(array $histogramRows): IndicationChartSeries
    {
        $labels = [];
        $values = [];

        foreach ($histogramRows as $row) {
            $labels[] = (string) $row['age'];
            $values[] = $row['count'];
        }

        return new IndicationChartSeries($labels, $values);
    }

    /**
     * @param array{male:int,female:int,other:int,unknown:int} $genderCounts
     */
    public function buildSummaryDeck(array $genderCounts, IndicationDashboardMetricsRow $metrics): IndicationSummaryDeck
    {
        $total = $metrics->totalIndication;

        return new IndicationSummaryDeck(
            $total,
            $this->buildGenderSegments($genderCounts, $total),
            $this->buildUrgencySegments($metrics),
        );
    }

    /**
     * @param array{male:int,female:int,other:int,unknown:int} $genderCounts
     *
     * @return list<IndicationSummarySegment>
     */
    public function buildGenderSegments(array $genderCounts, int $total): array
    {
        $codeCounts = [
            'M' => $genderCounts['male'],
            'F' => $genderCounts['female'],
            'X' => $genderCounts['other'],
        ];

        $segments = [];
        foreach (AllocationGender::cases() as $case) {
            $count = $codeCounts[$case->value] ?? 0;
            $segments[] = new IndicationSummarySegment(
                self::GENDER_BAR_CLASSES[$case->value] ?? 'bg-secondary',
                $case->label(),
                $count,
                $total > 0 ? round(100 * $count / $total, 1) : 0.0,
            );
        }

        return $segments;
    }

    /**
     * @return list<IndicationSummarySegment>
     */
    public function buildUrgencySegments(IndicationDashboardMetricsRow $metrics): array
    {
        $total = $metrics->totalIndication;
        $counts = [
            AllocationStatsUrgencyProjectionCode::Emergency->value => $metrics->urgencyEmergencyIndication,
            AllocationStatsUrgencyProjectionCode::Inpatient->value => $metrics->urgencyInpatientIndication,
            AllocationStatsUrgencyProjectionCode::Outpatient->value => $metrics->urgencyOutpatientIndication,
        ];

        $segments = [];
        foreach (AllocationStatsUrgencyProjectionCode::cases() as $code) {
            $count = $counts[$code->value] ?? 0;
            $segments[] = new IndicationSummarySegment(
                self::URGENCY_BAR_CLASSES[$code->value] ?? 'bg-secondary',
                $code->labelTranslationKey(),
                $count,
                $total > 0 ? round(100 * $count / $total, 1) : 0.0,
            );
        }

        return $segments;
    }

    /**
     * @param array<string, int> $ageGroupCounts
     *
     * @return list<IndicationDistributionRow>
     */
    public function buildAgeGroupDistribution(array $ageGroupCounts, int $total): array
    {
        $rows = [];

        foreach (StatisticsAgeGroupBucketSql::DISPLAY_BUCKET_KEYS as $key) {
            $count = $ageGroupCounts[$key] ?? 0;
            $rows[] = new IndicationDistributionRow(
                'stats.indication.age_group.'.$key,
                $count,
                $total > 0 ? round(100 * $count / $total, 1) : 0.0,
            );
        }

        return $rows;
    }

    /**
     * @param array{male:int,female:int,other:int,unknown:int} $genderCounts
     *
     * @return list<IndicationDistributionRow>
     */
    public function buildGenderDistribution(array $genderCounts, int $total): array
    {
        return [
            $this->distributionRow(AllocationStatsGenderProjectionCode::Male->labelTranslationKey(), $genderCounts['male'], $total),
            $this->distributionRow(AllocationStatsGenderProjectionCode::Female->labelTranslationKey(), $genderCounts['female'], $total),
            $this->distributionRow(AllocationStatsGenderProjectionCode::Other->labelTranslationKey(), $genderCounts['other'], $total),
            $this->distributionRow('stats.indication.gender.unknown', $genderCounts['unknown'], $total),
        ];
    }

    /**
     * @return list<IndicationDistributionRow>
     */
    public function buildUrgencyDistribution(IndicationDashboardMetricsRow $metrics): array
    {
        $total = $metrics->totalIndication;

        return [
            $this->distributionRow(AllocationStatsUrgencyProjectionCode::Emergency->labelTranslationKey(), $metrics->urgencyEmergencyIndication, $total),
            $this->distributionRow(AllocationStatsUrgencyProjectionCode::Inpatient->labelTranslationKey(), $metrics->urgencyInpatientIndication, $total),
            $this->distributionRow(AllocationStatsUrgencyProjectionCode::Outpatient->labelTranslationKey(), $metrics->urgencyOutpatientIndication, $total),
        ];
    }

    /**
     * @param array<string, int> $transportTimeBucketCounts
     *
     * @return list<IndicationDistributionRow>
     */
    public function buildTransportTimeDistribution(array $transportTimeBucketCounts, int $total): array
    {
        $rows = [];

        foreach (self::TRANSPORT_TIME_BUCKET_KEYS as $key) {
            $count = $transportTimeBucketCounts[$key] ?? 0;
            $rows[] = new IndicationDistributionRow(
                'statistics.distribution.transport_time_bucket.'.$key,
                $count,
                $total > 0 ? round(100 * $count / $total, 1) : 0.0,
            );
        }

        return $rows;
    }

    /**
     * @return list<IndicationDistributionRow>
     */
    public function buildTransportDistribution(IndicationDashboardMetricsRow $metrics): array
    {
        $total = $metrics->totalIndication;

        return [
            $this->distributionRow('stats.indication.transport.ground', $metrics->groundTransportIndication, $total),
            $this->distributionRow('stats.indication.transport.air', $metrics->airTransportIndication, $total),
        ];
    }

    /**
     * @return list<IndicationDistributionRow>
     */
    public function buildResourcesDistribution(IndicationDashboardMetricsRow $metrics): array
    {
        $total = $metrics->totalIndication;

        return [
            $this->distributionRow('statistics.distribution.dim.requires_resus', $metrics->resusIndication, $total),
            $this->distributionRow('statistics.distribution.dim.requires_cathlab', $metrics->cathlabIndication, $total),
        ];
    }

    /**
     * @return list<IndicationDistributionRow>
     */
    public function buildClinicalFeatures(IndicationDashboardMetricsRow $metrics): array
    {
        $total = $metrics->totalIndication;

        return [
            $this->distributionRow('statistics.distribution.dim.is_with_physician', $metrics->withPhysicianIndication, $total),
            $this->distributionRow('statistics.distribution.dim.is_cpr', $metrics->cprIndication, $total),
            $this->distributionRow('statistics.distribution.dim.is_ventilated', $metrics->ventilatedIndication, $total),
            $this->distributionRow('stats.analysis.feature.is_shock', $metrics->shockIndication, $total),
            $this->distributionRow('stats.analysis.feature.is_pregnant', $metrics->pregnantIndication, $total),
            $this->distributionRow('stats.analysis.feature.is_work_accident', $metrics->workAccidentIndication, $total),
            $this->distributionRow('field.infection', $metrics->infectiousIndication, $total),
        ];
    }

    /**
     * @param list<array{weekday:int,dayTimeBucketCode:int,count:int}> $cells
     */
    public function buildDayTimeHeatmap(array $cells): IndicationHeatmapData
    {
        return $this->buildWeekdayBucketHeatmap(
            $cells,
            'dayTimeBucketCode',
            array_map(
                static fn (AllocationStatsDayTimeBucketProjectionCode $case): int => $case->value,
                AllocationStatsDayTimeBucketProjectionCode::displayOrder(),
            ),
            static fn (int $code): string => AllocationStatsDayTimeBucketProjectionCode::from($code)->labelTranslationKey(),
        );
    }

    /**
     * @param list<array{weekday:int,shiftBucketCode:int,count:int}> $cells
     */
    public function buildShiftHeatmap(array $cells): IndicationHeatmapData
    {
        return $this->buildWeekdayBucketHeatmap(
            $cells,
            'shiftBucketCode',
            array_map(
                static fn (AllocationStatsShiftBucketProjectionCode $case): int => $case->value,
                AllocationStatsShiftBucketProjectionCode::displayOrder(),
            ),
            static fn (int $code): string => AllocationStatsShiftBucketProjectionCode::from($code)->labelTranslationKey(),
        );
    }

    /**
     * @param list<array<string, int>> $cells
     * @param list<int>                $bucketCodes
     */
    private function buildWeekdayBucketHeatmap(
        array $cells,
        string $bucketField,
        array $bucketCodes,
        \Closure $labelTranslationKey,
    ): IndicationHeatmapData {
        $columnLabels = array_map(
            fn (int $code): string => $this->translator->trans($labelTranslationKey($code), [], 'statistics'),
            $bucketCodes,
        );

        $matrix = [];
        $rowLabels = [];
        $rowKeys = [];
        $max = 0;

        foreach (self::ISO_WEEKDAYS as $weekday) {
            $rowKeys[] = (string) $weekday;
            $rowLabels[] = $this->translator->trans('stats.indication.weekday.'.$weekday, [], 'statistics');
            $row = [];
            foreach ($bucketCodes as $bucketCode) {
                $count = 0;
                foreach ($cells as $cell) {
                    if ($cell['weekday'] === $weekday && $cell[$bucketField] === $bucketCode) {
                        $count = $cell['count'];
                        break;
                    }
                }
                $row[] = $count;
                $max = max($max, $count);
            }
            $matrix[] = $row;
        }

        return new IndicationHeatmapData($rowLabels, $columnLabels, $matrix, $max, $rowKeys);
    }

    private function distributionRow(string $labelTranslationKey, int $count, int $total): IndicationDistributionRow
    {
        return new IndicationDistributionRow(
            $labelTranslationKey,
            $count,
            $total > 0 ? round(100 * $count / $total, 1) : 0.0,
        );
    }
}
