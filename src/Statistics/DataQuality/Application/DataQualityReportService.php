<?php

declare(strict_types=1);

namespace App\Statistics\DataQuality\Application;

use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Statistics\DataQuality\AllocationVolumeDataQualityCalculator;
use App\Statistics\DataQuality\CoverageDataQualityCalculator;
use App\Statistics\DataQuality\DataQualityExplanationBuilder;
use App\Statistics\DataQuality\DataQualityLevel;
use App\Statistics\DataQuality\DataQualityScoreCalculator;
use App\Statistics\DataQuality\Dto\DataQualityDimensionResult;
use App\Statistics\DataQuality\Dto\DataQualityReport;
use App\Statistics\DataQuality\Infrastructure\Query\DataQualityHospitalAllocationCountsQuery;
use App\Statistics\DataQuality\Infrastructure\Query\DataQualityParticipantHospitalIdsQuery;
use App\Statistics\DataQuality\RepresentativenessDataQualityCalculator;
use App\Statistics\DataQuality\SubgroupSupportDataQualityCalculator;

final readonly class DataQualityReportService
{
    public function __construct(
        private DataQualityPopulationResolver $populationResolver,
        private DataQualityParticipantHospitalIdsQuery $participantQuery,
        private DataQualityHospitalAllocationCountsQuery $allocationCountsQuery,
        private HospitalRepository $hospitalRepository,
        private CoverageDataQualityCalculator $coverageCalculator,
        private RepresentativenessDataQualityCalculator $representativenessCalculator,
        private SubgroupSupportDataQualityCalculator $subgroupSupportCalculator,
        private AllocationVolumeDataQualityCalculator $allocationVolumeCalculator,
        private DataQualityScoreCalculator $scoreCalculator,
        private DataQualityExplanationBuilder $explanationBuilder,
    ) {
    }

    public function build(DataQualityCriteria $criteria): DataQualityReport
    {
        $population = $this->populationResolver->resolve($criteria->filter, $criteria->user);
        $participantIds = $this->participantQuery->fetch(
            $criteria->indicationId,
            $criteria->period,
            $criteria->scope,
        );

        $coverage = $this->coverageCalculator->calculate(\count($population), \count($participantIds));
        $representativeness = $this->representativenessCalculator->calculate($population, $participantIds);
        $subgroupSupport = $this->subgroupSupportCalculator->calculate($population, $participantIds);

        $allocationCounts = $this->allocationCountsQuery->fetch(
            $criteria->indicationId,
            $criteria->period,
            $criteria->scope,
        );
        $hospitalNames = $this->hospitalRepository->findNamesByIds(array_keys($allocationCounts));
        $allocationVolume = $this->allocationVolumeCalculator->calculate($allocationCounts, $hospitalNames);

        $overallLevel = $this->scoreCalculator->calculateOverall(
            $coverage,
            $representativeness,
            $subgroupSupport,
            $allocationVolume,
        );

        [$explanationKey, $explanationParameters] = $this->explanationBuilder->build(
            $overallLevel,
            $coverage,
            $representativeness,
            $subgroupSupport,
            $allocationVolume,
        );

        return new DataQualityReport(
            $overallLevel,
            $explanationKey,
            $explanationParameters,
            $criteria->scopeLabel,
            $criteria->periodLabel,
            $coverage,
            $representativeness,
            $subgroupSupport,
            $allocationVolume,
            $this->buildDimensionSummaries($coverage, $representativeness, $subgroupSupport, $allocationVolume),
        );
    }

    /**
     * @return list<DataQualityDimensionResult>
     */
    private function buildDimensionSummaries(
        \App\Statistics\DataQuality\Dto\CoverageResult $coverage,
        \App\Statistics\DataQuality\Dto\RepresentativenessResult $representativeness,
        \App\Statistics\DataQuality\Dto\SubgroupSupportResult $subgroupSupport,
        \App\Statistics\DataQuality\Dto\AllocationVolumeResult $allocationVolume,
    ): array {
        return [
            new DataQualityDimensionResult(
                'coverage',
                $coverage->level,
                'stats.data_quality.dimension.coverage.metric',
                sprintf('%.1f %%', $coverage->coveragePercentage),
                'stats.data_quality.dimension.coverage.hint',
                [
                    'participating' => $coverage->participatingHospitals,
                    'total' => $coverage->totalHospitals,
                ],
            ),
            new DataQualityDimensionResult(
                'representativeness',
                $representativeness->level,
                'stats.data_quality.dimension.representativeness.metric',
                sprintf('%.2f', $representativeness->averageDifference),
                DataQualityLevel::High === $representativeness->level
                    ? 'stats.data_quality.dimension.representativeness.hint_similar'
                    : 'stats.data_quality.dimension.representativeness.hint_divergent',
            ),
            new DataQualityDimensionResult(
                'subgroup',
                $subgroupSupport->level,
                'stats.data_quality.dimension.subgroup.metric',
                sprintf('%d %%', (int) round($subgroupSupport->supportedPopulationShare * 100.0)),
                \count($subgroupSupport->weaklySupportedCells) > 0
                    ? 'stats.data_quality.dimension.subgroup.hint_weak'
                    : 'stats.data_quality.dimension.subgroup.hint_ok',
                ['weakCount' => \count($subgroupSupport->weaklySupportedCells)],
            ),
            new DataQualityDimensionResult(
                'allocation',
                $allocationVolume->level,
                'stats.data_quality.dimension.allocation.metric',
                sprintf('%d %%', (int) round($allocationVolume->shareHospitalsWithSufficientAllocations * 100.0)),
                DataQualityLevel::High === $allocationVolume->level
                    ? 'stats.data_quality.dimension.allocation.hint_ok'
                    : 'stats.data_quality.dimension.allocation.hint_low',
            ),
        ];
    }
}
