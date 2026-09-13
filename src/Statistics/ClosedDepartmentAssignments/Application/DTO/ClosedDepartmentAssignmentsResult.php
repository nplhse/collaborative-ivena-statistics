<?php

declare(strict_types=1);

namespace App\Statistics\ClosedDepartmentAssignments\Application\DTO;

final readonly class ClosedDepartmentAssignmentsResult
{
    /**
     * @param list<ClosedDepartmentNamedRow>        $departments
     * @param list<ClosedDepartmentNamedRow>        $specialities
     * @param list<ClosedDepartmentDistributionRow> $gender
     * @param list<ClosedDepartmentDistributionRow> $urgency
     * @param list<ClosedDepartmentNamedRow>        $indications
     * @param list<ClosedDepartmentNamedRow>        $occasions
     * @param list<ClosedDepartmentDistributionRow> $resources
     * @param list<ClosedDepartmentDistributionRow> $clinicalFeatures
     * @param list<ClosedDepartmentNamedRow>        $assignments
     * @param list<ClosedDepartmentNamedRow>        $infections
     * @param list<ClosedDepartmentNamedRow>        $dispatchAreas
     */
    public function __construct(
        public ClosedDepartmentKpiSet $kpis,
        public ClosedDepartmentTimeSeries $timeSeries,
        public ClosedDepartmentHeatmapData $heatmap,
        public array $departments,
        public array $specialities,
        public array $gender,
        public array $urgency,
        public array $indications,
        public array $occasions,
        public array $resources,
        public array $clinicalFeatures,
        public ClosedDepartmentTransportStats $transport,
        public array $assignments,
        public array $infections,
        public array $dispatchAreas,
        public ?string $allClosedExploreUrl,
        public bool $hasClosedCases,
    ) {
    }

    public static function forSummary(
        ClosedDepartmentKpiSet $kpis,
        ClosedDepartmentTimeSeries $timeSeries,
        ClosedDepartmentHeatmapData $heatmap,
        ?string $allClosedExploreUrl,
        bool $hasClosedCases,
    ): self {
        return new self(
            $kpis,
            $timeSeries,
            $heatmap,
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            ClosedDepartmentTransportStats::empty(),
            [],
            [],
            [],
            $allClosedExploreUrl,
            $hasClosedCases,
        );
    }

    /**
     * @param list<ClosedDepartmentDistributionRow> $gender
     * @param list<ClosedDepartmentDistributionRow> $urgency
     * @param list<ClosedDepartmentDistributionRow> $resources
     * @param list<ClosedDepartmentDistributionRow> $clinicalFeatures
     * @param list<ClosedDepartmentNamedRow>        $dispatchAreas
     */
    public static function forDetails(
        ClosedDepartmentKpiSet $kpis,
        array $gender,
        array $urgency,
        array $resources,
        array $clinicalFeatures,
        ClosedDepartmentTransportStats $transport,
        array $dispatchAreas,
        ?string $allClosedExploreUrl,
        bool $hasClosedCases,
    ): self {
        return new self(
            $kpis,
            new ClosedDepartmentTimeSeries([], [], []),
            new ClosedDepartmentHeatmapData([], [], [], 0),
            [],
            [],
            $gender,
            $urgency,
            [],
            [],
            $resources,
            $clinicalFeatures,
            $transport,
            [],
            [],
            $dispatchAreas,
            $allClosedExploreUrl,
            $hasClosedCases,
        );
    }
}
