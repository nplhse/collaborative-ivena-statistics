<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Form\Data;

use App\Statistics\UI\Form\Data\StatisticsScopePeriodFormData;

final class ExplorerEditFormData
{
    public function __construct(
        public StatisticsScopePeriodFormData $scopePeriod = new StatisticsScopePeriodFormData(),
        public string $dataSource = 'allocations',
        public string $rowDimension = 'time',
        public ?string $rowGrain = 'month',
        public ?string $columnDimension = null,
        public ?string $columnGrain = null,
        public string $metric = 'allocation_count',
        public bool $showPercentOfTotal = false,
        public string $chartType = 'bar',
        public string $tableLayout = 'flat',
        public string $chartRowLimit = 'all',
        public string $hospitalPopulation = 'participating',
        /** @var list<string> */
        public array $additionalTableMetrics = [],
        public ?int $filterDepartmentId = null,
        public ?int $filterSpecialityId = null,
        public ?int $filterUrgency = null,
        public ?int $filterTransportType = null,
        public ?int $filterGender = null,
        public ?string $filterAgeGroup = null,
        public ?bool $filterResus = null,
        public ?bool $filterCpr = null,
        public ?bool $filterVentilation = null,
        public ?int $filterAssignmentId = null,
        public ?int $filterIndicationId = null,
        public ?int $filterSecondaryIndicationId = null,
        public ?int $filterIndicationGroupId = null,
    ) {
    }
}
