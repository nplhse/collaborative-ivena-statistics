<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerDimensionCategory;

final class ExplorerDimensionCatalog
{
    /**
     * @return list<ExplorerDimensionCategory>
     */
    public function categoryOrderFor(AnalysisDataSourceKey $dataSourceKey): array
    {
        return match ($dataSourceKey) {
            AnalysisDataSourceKey::Allocations => [
                ExplorerDimensionCategory::TimeAndCalendar,
                ExplorerDimensionCategory::MissionAndAllocation,
                ExplorerDimensionCategory::PatientAndDemographics,
                ExplorerDimensionCategory::ClinicalCare,
                ExplorerDimensionCategory::TransportAndDuration,
                ExplorerDimensionCategory::HospitalAndGeography,
            ],
            AnalysisDataSourceKey::Hospitals => [
                ExplorerDimensionCategory::HospitalProfile,
                ExplorerDimensionCategory::GeographyAndParticipation,
            ],
        };
    }

    /**
     * @return list<string>
     */
    public function categoryGroupOrderFor(AnalysisDataSourceKey $dataSourceKey): array
    {
        return array_map(
            static fn (ExplorerDimensionCategory $category): string => $category->labelTranslationKey(),
            $this->categoryOrderFor($dataSourceKey),
        );
    }
}
