<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application;

use App\Statistics\Application\Mapping\AllocationStatsHospitalLocationProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsHospitalTierProjectionCode;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowDestinationPoolSlice;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowDistributionSegment;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowStructureDistributionCard;

final class CaseFlowStructureDistributionAssembler
{
    /** @var array<string, array{barClass: string, labelKey: string}> */
    private const array SIZE_SEGMENTS = [
        'Small' => ['barClass' => 'bg-cyan', 'labelKey' => 'hospital.size.Small'],
        'Medium' => ['barClass' => 'bg-blue', 'labelKey' => 'hospital.size.Medium'],
        'Large' => ['barClass' => 'bg-indigo', 'labelKey' => 'hospital.size.Large'],
        CaseFlowPrivacyPolicy::SUPPRESSED_POOL_KEY => ['barClass' => 'bg-secondary-lt', 'labelKey' => 'stats.case_flow.pool.suppressed'],
        'unknown' => ['barClass' => 'bg-secondary', 'labelKey' => 'stats.case_flow.pool.unknown'],
    ];

    /**
     * @param list<CaseFlowDestinationPoolSlice>                       $slices
     * @param array<string, array{barClass: string, labelKey: string}> $segmentOrder
     */
    public function buildCard(
        string $titleTranslationKey,
        array $slices,
        array $segmentOrder,
    ): CaseFlowStructureDistributionCard {
        $countsByKey = [];
        $total = 0;
        foreach ($slices as $slice) {
            $countsByKey[$slice->poolKey] = ($countsByKey[$slice->poolKey] ?? 0) + $slice->caseCount;
            $total += $slice->caseCount;
        }

        $segments = [];
        foreach ($segmentOrder as $poolKey => $definition) {
            $count = $countsByKey[$poolKey] ?? 0;
            if (0 === $count && CaseFlowPrivacyPolicy::SUPPRESSED_POOL_KEY !== $poolKey) {
                continue;
            }
            $segments[] = new CaseFlowDistributionSegment(
                $definition['barClass'],
                $definition['labelKey'],
                $count,
                $total > 0 ? round(((float) $count / (float) $total) * 100.0, 1) : 0.0,
            );
        }

        return new CaseFlowStructureDistributionCard($titleTranslationKey, $segments);
    }

    /**
     * @param list<CaseFlowDestinationPoolSlice> $slices
     */
    public function tierCard(array $slices): CaseFlowStructureDistributionCard
    {
        return $this->buildCard('stats.case_flow.section.destination_tier', $slices, $this->tierSegments());
    }

    /**
     * @param list<CaseFlowDestinationPoolSlice> $slices
     */
    public function locationCard(array $slices): CaseFlowStructureDistributionCard
    {
        return $this->buildCard('stats.case_flow.section.destination_location', $slices, $this->locationSegments());
    }

    /**
     * @param list<CaseFlowDestinationPoolSlice> $slices
     */
    public function sizeCard(array $slices): CaseFlowStructureDistributionCard
    {
        return $this->buildCard('stats.case_flow.section.destination_size', $slices, self::SIZE_SEGMENTS);
    }

    /**
     * @return array<string, array{barClass: string, labelKey: string}>
     */
    private function tierSegments(): array
    {
        /** @var array<string, array{barClass: string, labelKey: string}> $segments */
        $segments = [
            (string) AllocationStatsHospitalTierProjectionCode::Basic->value => ['barClass' => 'bg-secondary', 'labelKey' => 'stats.case_flow.tier.basic'],
            (string) AllocationStatsHospitalTierProjectionCode::Extended->value => ['barClass' => 'bg-primary', 'labelKey' => 'stats.case_flow.tier.extended'],
            (string) AllocationStatsHospitalTierProjectionCode::Full->value => ['barClass' => 'bg-indigo', 'labelKey' => 'stats.case_flow.tier.full'],
            CaseFlowPrivacyPolicy::SUPPRESSED_POOL_KEY => ['barClass' => 'bg-secondary-lt', 'labelKey' => 'stats.case_flow.pool.suppressed'],
        ];

        return $segments;
    }

    /**
     * @return array<string, array{barClass: string, labelKey: string}>
     */
    private function locationSegments(): array
    {
        /** @var array<string, array{barClass: string, labelKey: string}> $segments */
        $segments = [
            (string) AllocationStatsHospitalLocationProjectionCode::Urban->value => ['barClass' => 'bg-azure', 'labelKey' => 'stats.case_flow.location.urban'],
            (string) AllocationStatsHospitalLocationProjectionCode::Mixed->value => ['barClass' => 'bg-teal', 'labelKey' => 'stats.case_flow.location.mixed'],
            (string) AllocationStatsHospitalLocationProjectionCode::Rural->value => ['barClass' => 'bg-lime', 'labelKey' => 'stats.case_flow.location.rural'],
            CaseFlowPrivacyPolicy::SUPPRESSED_POOL_KEY => ['barClass' => 'bg-secondary-lt', 'labelKey' => 'stats.case_flow.pool.suppressed'],
        ];

        return $segments;
    }
}
