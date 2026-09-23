<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Form\Data;

use App\Statistics\AnalysisExplorer\Application\ExplorerAssistantFilters;
use App\Statistics\AnalysisExplorer\Application\ExplorerAssistantQuery;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerAssistantGoal;
use App\Statistics\UI\Form\Data\StatisticsScopePeriodFormData;

final class ExplorerAssistantDraft
{
    public string $currentStep = 'goal';

    public StatisticsScopePeriodFormData $scopePeriod;

    public ?ExplorerAssistantGoal $goal = null;

    public AnalysisDataSourceKey $dataSource = AnalysisDataSourceKey::Allocations;

    public ?AnalysisDimensionKey $row = null;

    public ?AnalysisDimensionKey $column = null;

    public ?AnalysisDimensionGrain $grain = null;

    public ?AnalysisDimensionGrain $columnGrain = null;

    public AnalysisMetricKey $metric = AnalysisMetricKey::AllocationCount;

    public ?int $filterDepartmentId = null;

    public ?int $filterSpecialityId = null;

    public ?int $filterUrgency = null;

    public ?int $filterTransportType = null;

    public ?int $filterGender = null;

    public ?string $filterAgeGroup = null;

    public ?bool $filterResus = null;

    public ?bool $filterCpr = null;

    public ?bool $filterVentilation = null;

    public ?int $filterAssignmentId = null;

    public ?int $filterIndicationId = null;

    public ?int $filterSecondaryIndicationId = null;

    public ?int $filterIndicationGroupId = null;

    public function __construct()
    {
        $this->scopePeriod = new StatisticsScopePeriodFormData();
    }

    /**
     * @return list<string>
     */
    public function axisDimensionKeys(): array
    {
        $keys = [];
        foreach ([$this->row, $this->column] as $dimension) {
            if ($dimension instanceof AnalysisDimensionKey && !$dimension->isTemporalPrimary()) {
                $keys[] = $dimension->registryKey();
            }
        }

        return $keys;
    }

    public function toQuery(): ExplorerAssistantQuery
    {
        return new ExplorerAssistantQuery(
            $this->goal,
            $this->row,
            $this->column,
            $this->grain,
            $this->columnGrain,
            $this->metric,
            $this->dataSource,
            new ExplorerAssistantFilters(
                departmentId: $this->filterDepartmentId,
                specialityId: $this->filterSpecialityId,
                urgency: $this->filterUrgency,
                transportType: $this->filterTransportType,
                gender: $this->filterGender,
                ageGroup: $this->filterAgeGroup,
                resus: $this->filterResus,
                cpr: $this->filterCpr,
                ventilation: $this->filterVentilation,
                assignmentId: $this->filterAssignmentId,
                indicationId: $this->filterIndicationId,
                secondaryIndicationId: $this->filterSecondaryIndicationId,
                indicationGroupId: $this->filterIndicationGroupId,
            ),
            false,
        );
    }
}
