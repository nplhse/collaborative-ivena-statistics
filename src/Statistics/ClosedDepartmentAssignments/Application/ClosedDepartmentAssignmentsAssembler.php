<?php

declare(strict_types=1);

namespace App\Statistics\ClosedDepartmentAssignments\Application;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\Mapping\StatisticsTransportTimeBucketSql;
use App\Statistics\Application\TimeSeries\TimeSeriesAxisFiller;
use App\Statistics\Application\TimeSeries\TimeSeriesGrain;
use App\Statistics\ClosedDepartmentAssignments\Application\DTO\ClosedDepartmentAssignmentsCriteria;
use App\Statistics\ClosedDepartmentAssignments\Application\DTO\ClosedDepartmentDistributionRow;
use App\Statistics\ClosedDepartmentAssignments\Application\DTO\ClosedDepartmentHeatmapData;
use App\Statistics\ClosedDepartmentAssignments\Application\DTO\ClosedDepartmentKpiSet;
use App\Statistics\ClosedDepartmentAssignments\Application\DTO\ClosedDepartmentNamedRow;
use App\Statistics\ClosedDepartmentAssignments\Application\DTO\ClosedDepartmentTimeSeries;
use App\Statistics\ClosedDepartmentAssignments\Application\DTO\ClosedDepartmentTransportStats;
use App\Statistics\ClosedDepartmentAssignments\Infrastructure\Query\Dto\ClosedDepartmentMetricsRow;
use App\Statistics\ClosedDepartmentAssignments\Infrastructure\Query\Dto\ClosedDepartmentSliceData;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ClosedDepartmentAssignmentsAssembler
{
    /** @var list<int> */
    private const array ISO_WEEKDAYS = [1, 2, 3, 4, 5, 6, 7];

    private const int TWO_HOUR_SLOT_COUNT = 12;

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
        private ClosedDepartmentExploreUrlFactory $exploreUrlFactory,
    ) {
    }

    public function kpis(ClosedDepartmentMetricsRow $metrics): ClosedDepartmentKpiSet
    {
        return new ClosedDepartmentKpiSet(
            $metrics->closedCount,
            $metrics->totalCount,
            ClosedDepartmentShareMath::percent($metrics->closedCount, $metrics->totalCount),
            $metrics->closedDepartmentCount,
            $metrics->totalDepartmentCount,
            $metrics->closedMeanTransportMinutes,
        );
    }

    public function timeSeries(
        ClosedDepartmentSliceData $slice,
        TimeSeriesGrain $grain,
        StatisticsPeriodBounds $period,
        ?\DateTimeImmutable $now = null,
    ): ClosedDepartmentTimeSeries {
        $closedByKey = [];
        $totalByKey = [];
        foreach ($slice->timeSeriesRows as $row) {
            $key = TimeSeriesAxisFiller::isoKeyFromRow($row, $grain);
            $closedByKey[$key] = $row['closed'];
            $totalByKey[$key] = $row['total'];
        }

        $filledTotal = TimeSeriesAxisFiller::fill(
            $grain,
            $period,
            $totalByKey,
            $now ?? new \DateTimeImmutable('now'),
        );
        if ([] === $filledTotal) {
            return new ClosedDepartmentTimeSeries([], [], []);
        }

        $labels = [];
        $counts = [];
        $shares = [];
        foreach ($filledTotal as $point) {
            $labels[] = $point['key'];
            $closed = $closedByKey[$point['key']] ?? 0;
            $counts[] = $closed;
            $shares[] = ClosedDepartmentShareMath::percent($closed, $point['count']);
        }

        return new ClosedDepartmentTimeSeries($labels, $counts, $shares);
    }

    /**
     * @param list<array{id: ?int, name: string, closed: int, total: int}> $rows
     * @param array<string, scalar|null>                                   $exploreExtra
     *
     * @return list<ClosedDepartmentNamedRow>
     */
    public function namedRows(
        array $rows,
        int $closedTotal,
        ClosedDepartmentAssignmentsCriteria $criteria,
        string $idQueryKey,
        array $exploreExtra = [],
    ): array {
        $unknownLabel = $this->translator->trans('stats.closed_department.label.unknown', [], 'statistics');
        $out = [];
        foreach ($rows as $row) {
            $name = '' !== $row['name'] ? $row['name'] : $unknownLabel;
            $extra = $exploreExtra;
            if (null !== $row['id']) {
                $extra[$idQueryKey] = $row['id'];
            } elseif ('occasion' === $idQueryKey) {
                $extra['occasion'] = 'none';
            }

            $out[] = new ClosedDepartmentNamedRow(
                $row['id'],
                $name,
                $row['closed'],
                ClosedDepartmentShareMath::percent($row['closed'], $closedTotal),
                $this->exploreUrl($criteria, $extra),
            );
        }

        return $out;
    }

    /**
     * @return list<ClosedDepartmentDistributionRow>
     */
    public function gender(ClosedDepartmentMetricsRow $metrics): array
    {
        $counts = [
            AllocationGender::MALE->value => [$metrics->closedMale, $metrics->regularMale],
            AllocationGender::FEMALE->value => [$metrics->closedFemale, $metrics->regularFemale],
            AllocationGender::OTHER->value => [$metrics->closedOther, $metrics->regularOther],
        ];

        $rows = [];
        foreach (AllocationGender::cases() as $gender) {
            [$closedCount, $regularCount] = $counts[$gender->value];
            if (AllocationGender::OTHER === $gender && 0 === $closedCount && 0 === $regularCount) {
                continue;
            }

            $rows[] = $this->distributionRow(
                $gender->label(),
                $closedCount,
                $metrics->closedCount,
                $regularCount,
                $metrics->regularCount,
                null,
                self::GENDER_BAR_CLASSES[$gender->value] ?? 'bg-secondary',
            );
        }

        return $rows;
    }

    /**
     * @return list<ClosedDepartmentDistributionRow>
     */
    public function urgency(
        ClosedDepartmentMetricsRow $metrics,
        ClosedDepartmentAssignmentsCriteria $criteria,
    ): array {
        return [
            $this->distributionRow(
                'stats.closed_department.urgency.sk1',
                $metrics->closedSk1,
                $metrics->closedCount,
                $metrics->regularSk1,
                $metrics->regularCount,
                $criteria,
                self::URGENCY_BAR_CLASSES[1],
                ['urgency' => '1'],
            ),
            $this->distributionRow(
                'stats.closed_department.urgency.sk2',
                $metrics->closedSk2,
                $metrics->closedCount,
                $metrics->regularSk2,
                $metrics->regularCount,
                $criteria,
                self::URGENCY_BAR_CLASSES[2],
                ['urgency' => '2'],
            ),
            $this->distributionRow(
                'stats.closed_department.urgency.sk3',
                $metrics->closedSk3,
                $metrics->closedCount,
                $metrics->regularSk3,
                $metrics->regularCount,
                $criteria,
                self::URGENCY_BAR_CLASSES[3],
                ['urgency' => '3'],
            ),
        ];
    }

    /**
     * @return list<ClosedDepartmentDistributionRow>
     */
    public function resources(
        ClosedDepartmentMetricsRow $metrics,
        ClosedDepartmentAssignmentsCriteria $criteria,
    ): array {
        return [
            $this->distributionRow(
                'statistics.distribution.dim.requires_resus',
                $metrics->closedResus,
                $metrics->closedCount,
                $metrics->regularResus,
                $metrics->regularCount,
                $criteria,
                extra: ['requiresResus' => 1],
            ),
            $this->distributionRow(
                'statistics.distribution.dim.requires_cathlab',
                $metrics->closedCathlab,
                $metrics->closedCount,
                $metrics->regularCathlab,
                $metrics->regularCount,
                $criteria,
                extra: ['requiresCathlab' => 1],
            ),
        ];
    }

    /**
     * @return list<ClosedDepartmentDistributionRow>
     */
    public function clinicalFeatures(
        ClosedDepartmentMetricsRow $metrics,
        ClosedDepartmentAssignmentsCriteria $criteria,
    ): array {
        return [
            $this->distributionRow(
                'statistics.distribution.dim.is_with_physician',
                $metrics->closedWithPhysician,
                $metrics->closedCount,
                $metrics->regularWithPhysician,
                $metrics->regularCount,
                $criteria,
                extra: [],
            ),
            $this->distributionRow(
                'statistics.distribution.dim.is_cpr',
                $metrics->closedCpr,
                $metrics->closedCount,
                $metrics->regularCpr,
                $metrics->regularCount,
                $criteria,
                extra: ['isCPR' => 1],
            ),
            $this->distributionRow(
                'statistics.distribution.dim.is_ventilated',
                $metrics->closedVentilated,
                $metrics->closedCount,
                $metrics->regularVentilated,
                $metrics->regularCount,
                $criteria,
                extra: ['isVentilated' => 1],
            ),
            $this->distributionRow(
                'stats.analysis.feature.is_shock',
                $metrics->closedShock,
                $metrics->closedCount,
                $metrics->regularShock,
                $metrics->regularCount,
                $criteria,
                extra: ['isShock' => 1],
            ),
            $this->distributionRow(
                'stats.analysis.feature.is_pregnant',
                $metrics->closedPregnant,
                $metrics->closedCount,
                $metrics->regularPregnant,
                $metrics->regularCount,
                $criteria,
                extra: ['isPregnant' => 1],
            ),
        ];
    }

    public function transport(
        ClosedDepartmentMetricsRow $metrics,
        ClosedDepartmentSliceData $slice,
    ): ClosedDepartmentTransportStats {
        [$closedBuckets, $regularBuckets, $totalBuckets] = $this->alignedTransportBuckets(
            $slice->closedTransportBuckets,
            $slice->regularTransportBuckets,
            $metrics->closedCount,
            $metrics->regularCount,
            $metrics->totalCount,
        );

        return new ClosedDepartmentTransportStats(
            $this->roundMinutes($metrics->closedMeanTransportMinutes),
            $this->roundMinutes($metrics->regularMeanTransportMinutes),
            ClosedDepartmentShareMath::deltaMinutes(
                $metrics->closedMeanTransportMinutes,
                $metrics->regularMeanTransportMinutes,
            ),
            $closedBuckets,
            $regularBuckets,
            $totalBuckets,
        );
    }

    public function weekdayDayTimeHeatmap(ClosedDepartmentSliceData $slice): ClosedDepartmentHeatmapData
    {
        $counts = [];
        foreach ($slice->weekdayDayTimeCells as $cell) {
            $counts[$cell['weekday']][$cell['twoHourSlot']] = $cell['count'];
        }

        $columnLabels = [];
        for ($slot = 0; $slot < self::TWO_HOUR_SLOT_COUNT; ++$slot) {
            $columnLabels[] = sprintf('%02d–%02d', $slot * 2, ($slot + 1) * 2);
        }

        $matrix = [];
        $rowLabels = [];
        $max = 0;
        foreach (self::ISO_WEEKDAYS as $weekday) {
            $rowLabels[] = $this->translator->trans('stats.indication.weekday.'.$weekday, [], 'statistics');
            $row = [];
            for ($slot = 0; $slot < self::TWO_HOUR_SLOT_COUNT; ++$slot) {
                $count = $counts[$weekday][$slot] ?? 0;
                $row[] = $count;
                $max = max($max, $count);
            }
            $matrix[] = $row;
        }

        return new ClosedDepartmentHeatmapData($rowLabels, $columnLabels, $matrix, $max);
    }

    /**
     * @param array<string, scalar|null> $extra
     */
    public function exploreUrl(ClosedDepartmentAssignmentsCriteria $criteria, array $extra = []): ?string
    {
        if (!$criteria->canExplore) {
            return null;
        }

        return $this->exploreUrlFactory->listUrl($criteria->filter, $criteria->period, $extra);
    }

    /**
     * @param array<string, int> $closedCounts
     * @param array<string, int> $regularCounts
     *
     * @return array{0: list<ClosedDepartmentDistributionRow>, 1: list<ClosedDepartmentDistributionRow>, 2: list<ClosedDepartmentDistributionRow>}
     */
    private function alignedTransportBuckets(
        array $closedCounts,
        array $regularCounts,
        int $closedTotal,
        int $regularTotal,
        int $allTotal,
    ): array {
        $keys = StatisticsTransportTimeBucketSql::DISPLAY_BUCKET_KEYS;
        if (($closedCounts['unknown'] ?? 0) + ($regularCounts['unknown'] ?? 0) > 0) {
            $keys[] = 'unknown';
        }

        $closedRows = [];
        $regularRows = [];
        $totalRows = [];
        foreach ($keys as $key) {
            $closedCount = $closedCounts[$key] ?? 0;
            $regularCount = $regularCounts[$key] ?? 0;
            $label = StatisticsTransportTimeBucketSql::translationKey($key);
            $closedRows[] = new ClosedDepartmentDistributionRow(
                $label,
                $closedCount,
                ClosedDepartmentShareMath::percent($closedCount, $closedTotal),
            );
            $regularRows[] = new ClosedDepartmentDistributionRow(
                $label,
                $regularCount,
                ClosedDepartmentShareMath::percent($regularCount, $regularTotal),
            );
            $totalRows[] = new ClosedDepartmentDistributionRow(
                $label,
                $closedCount + $regularCount,
                ClosedDepartmentShareMath::percent($closedCount + $regularCount, $allTotal),
            );
        }

        return [$closedRows, $regularRows, $totalRows];
    }

    /**
     * @param array<string, scalar|null>|null $extra
     */
    private function distributionRow(
        string $key,
        int $closedCount,
        int $closedTotal,
        int $regularCount,
        int $regularTotal,
        ?ClosedDepartmentAssignmentsCriteria $criteria,
        string $barClass = 'bg-primary',
        ?array $extra = null,
    ): ClosedDepartmentDistributionRow {
        $closedPercent = ClosedDepartmentShareMath::percent($closedCount, $closedTotal);
        $regularPercent = ClosedDepartmentShareMath::percent($regularCount, $regularTotal);
        $exploreUrl = null;
        if ($criteria instanceof ClosedDepartmentAssignmentsCriteria && null !== $extra) {
            $exploreUrl = [] === $extra
                ? $this->exploreUrl($criteria)
                : $this->exploreUrl($criteria, $extra);
        }

        return new ClosedDepartmentDistributionRow(
            $key,
            $closedCount,
            $closedPercent ?? 0.0,
            $exploreUrl,
            $barClass,
            $regularPercent,
            ClosedDepartmentShareMath::deltaPp($closedPercent, $regularPercent),
        );
    }

    private function roundMinutes(?float $value): ?float
    {
        if (null === $value) {
            return null;
        }

        return round($value, 1);
    }
}
