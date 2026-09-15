<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application;

use App\Statistics\Application\Mapping\AllocationStatsHospitalLocationProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsHospitalTierProjectionCode;
use App\Statistics\Application\Mapping\StatisticsTransportTimeBucketSql;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowDistributionSlice;
use App\Statistics\CaseFlow\Infrastructure\Query\Dto\CaseFlowBucketRow;

final class CaseFlowDistributionBuilder
{
    /**
     * @param list<CaseFlowBucketRow> $rows
     *
     * @return list<CaseFlowDistributionSlice>
     */
    public function buildTransportTime(array $rows, int $totalCases): array
    {
        return $this->buildFromBuckets($rows, $totalCases, static fn (string $key): string => 'stats.distribution.transport_time_bucket.'.$key);
    }

    /**
     * @return array<string, string>
     */
    public function tierLabelKeys(): array
    {
        /** @var array<string, string> $labelKeys */
        $labelKeys = [
            (string) AllocationStatsHospitalTierProjectionCode::Basic->value => 'stats.case_flow.tier.basic',
            (string) AllocationStatsHospitalTierProjectionCode::Extended->value => 'stats.case_flow.tier.extended',
            (string) AllocationStatsHospitalTierProjectionCode::Full->value => 'stats.case_flow.tier.full',
        ];

        return $labelKeys;
    }

    /**
     * @return array<string, string>
     */
    public function locationLabelKeys(): array
    {
        /** @var array<string, string> $labelKeys */
        $labelKeys = [
            (string) AllocationStatsHospitalLocationProjectionCode::Urban->value => 'stats.case_flow.location.urban',
            (string) AllocationStatsHospitalLocationProjectionCode::Mixed->value => 'stats.case_flow.location.mixed',
            (string) AllocationStatsHospitalLocationProjectionCode::Rural->value => 'stats.case_flow.location.rural',
        ];

        return $labelKeys;
    }

    /**
     * @return array<string, string>
     */
    public function sizeLabelKeys(): array
    {
        return [
            'Small' => 'stats.case_flow.size.small',
            'Medium' => 'stats.case_flow.size.medium',
            'Large' => 'stats.case_flow.size.large',
        ];
    }

    /**
     * @param list<CaseFlowBucketRow>  $rows
     * @param callable(string): string $labelResolver
     *
     * @return list<CaseFlowDistributionSlice>
     */
    private function buildFromBuckets(array $rows, int $totalCases, callable $labelResolver): array
    {
        if ($totalCases <= 0) {
            return [];
        }

        $displayKeys = StatisticsTransportTimeBucketSql::DISPLAY_BUCKET_KEYS;
        $ordered = [];
        foreach ($displayKeys as $key) {
            $ordered[$key] = 0;
        }

        foreach ($rows as $row) {
            if (!\in_array($row->bucketKey, $displayKeys, true)) {
                continue;
            }
            $ordered[$row->bucketKey] += $row->caseCount;
        }

        $slices = [];
        foreach ($ordered as $key => $count) {
            $slices[] = new CaseFlowDistributionSlice(
                $key,
                $labelResolver($key),
                $count,
                round(((float) $count / (float) $totalCases) * 100.0, 1),
            );
        }

        return $slices;
    }
}
