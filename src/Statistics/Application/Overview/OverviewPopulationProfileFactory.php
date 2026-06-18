<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Statistics\Application\Mapping\StatisticsAgeGroupBucketSql;
use App\Statistics\Application\Overview\Dto\OverviewDistributionRow;
use App\Statistics\Application\Overview\Dto\OverviewDistributionSegment;
use App\Statistics\Infrastructure\Query\Overview\Dto\OverviewDashboardMetricsResult;

final readonly class OverviewPopulationProfileFactory
{
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

    /** @var array<int, string> */
    private const array URGENCY_SHORT_LABEL_KEYS = [
        1 => 'stats.overview.hospital_summary.urgency_u1',
        2 => 'stats.overview.hospital_summary.urgency_u2',
        3 => 'stats.overview.hospital_summary.urgency_u3',
    ];

    /**
     * @return array{
     *     gender: array{titleTranslationKey: string, segments: list<OverviewDistributionSegment>},
     *     urgency: array{titleTranslationKey: string, segments: list<OverviewDistributionSegment>},
     *     age: list<OverviewDistributionRow>
     * }
     */
    public function build(OverviewDashboardMetricsResult $metrics): array
    {
        $total = $metrics->scopedTotal;

        return [
            'gender' => [
                'titleTranslationKey' => 'stats.overview.executive.population.gender',
                'segments' => $this->genderSegments($metrics, $total),
            ],
            'urgency' => [
                'titleTranslationKey' => 'stats.overview.executive.population.urgency',
                'segments' => $this->urgencySegments($metrics, $total),
            ],
            'age' => $this->ageRows($metrics, $total),
        ];
    }

    /**
     * @return list<OverviewDistributionSegment>
     */
    private function genderSegments(OverviewDashboardMetricsResult $metrics, int $total): array
    {
        $segments = [];
        foreach (AllocationGender::cases() as $case) {
            $count = $metrics->genderCounts[$case->value] ?? 0;
            $segments[] = new OverviewDistributionSegment(
                self::GENDER_BAR_CLASSES[$case->value] ?? 'bg-secondary',
                $case->label(),
                $count,
                $total > 0 ? round(100 * $count / $total, 1) : 0.0,
            );
        }

        return $segments;
    }

    /**
     * @return list<OverviewDistributionSegment>
     */
    private function urgencySegments(OverviewDashboardMetricsResult $metrics, int $total): array
    {
        $segments = [];
        foreach (AllocationUrgency::cases() as $case) {
            $count = $metrics->urgencyCounts[$case->value] ?? 0;
            $segments[] = new OverviewDistributionSegment(
                self::URGENCY_BAR_CLASSES[$case->value] ?? 'bg-secondary',
                self::URGENCY_SHORT_LABEL_KEYS[$case->value] ?? $case->label(),
                $count,
                $total > 0 ? round(100 * $count / $total, 1) : 0.0,
            );
        }

        return $segments;
    }

    /**
     * @return list<OverviewDistributionRow>
     */
    private function ageRows(OverviewDashboardMetricsResult $metrics, int $total): array
    {
        $rows = [];
        foreach (StatisticsAgeGroupBucketSql::DISPLAY_BUCKET_KEYS as $bucketKey) {
            $count = $metrics->ageGroupCounts[$bucketKey] ?? 0;
            if (0 === $count) {
                continue;
            }
            $rows[] = new OverviewDistributionRow(
                'stats.benchmark.age_group.'.$bucketKey,
                $count,
                $total > 0 ? round(100 * $count / $total, 1) : 0.0,
            );
        }

        return $rows;
    }
}
