<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;

final class ExplorerEditFormFilterFieldMapper
{
    /**
     * @param array<string, mixed> $submitted
     */
    public function mergeSubmittedFilters(ExplorerEditFormData $data, array $submitted): ExplorerEditFormData
    {
        return new ExplorerEditFormData(
            scopePeriod: $data->scopePeriod,
            dataSource: $data->dataSource,
            rowDimension: $data->rowDimension,
            rowGrain: $data->rowGrain,
            columnDimension: $data->columnDimension,
            columnGrain: $data->columnGrain,
            metric: $data->metric,
            showPercentOfTotal: $data->showPercentOfTotal,
            chartType: $data->chartType,
            tableLayout: $data->tableLayout,
            chartRowLimit: $data->chartRowLimit,
            hospitalPopulation: $data->hospitalPopulation,
            additionalTableMetrics: $data->additionalTableMetrics,
            filterDepartmentId: $this->nullableIntFromSubmitted($submitted, 'filterDepartmentId', $data->filterDepartmentId),
            filterSpecialityId: $this->nullableIntFromSubmitted($submitted, 'filterSpecialityId', $data->filterSpecialityId),
            filterUrgency: $this->nullableIntFromSubmitted($submitted, 'filterUrgency', $data->filterUrgency),
            filterTransportType: $this->nullableIntFromSubmitted($submitted, 'filterTransportType', $data->filterTransportType),
            filterGender: $this->nullableIntFromSubmitted($submitted, 'filterGender', $data->filterGender),
            filterAgeGroup: $this->nullableStringFromSubmitted($submitted, 'filterAgeGroup', $data->filterAgeGroup),
            filterResus: $this->nullableBoolFromSubmitted($submitted, 'filterResus', $data->filterResus),
            filterCpr: $this->nullableBoolFromSubmitted($submitted, 'filterCpr', $data->filterCpr),
            filterVentilation: $this->nullableBoolFromSubmitted($submitted, 'filterVentilation', $data->filterVentilation),
            filterAssignmentId: $this->nullableIntFromSubmitted($submitted, 'filterAssignmentId', $data->filterAssignmentId),
            filterIndicationId: $this->nullableIntFromSubmitted($submitted, 'filterIndicationId', $data->filterIndicationId),
            filterSecondaryIndicationId: $this->nullableIntFromSubmitted($submitted, 'filterSecondaryIndicationId', $data->filterSecondaryIndicationId),
            filterIndicationGroupId: $this->nullableIntFromSubmitted($submitted, 'filterIndicationGroupId', $data->filterIndicationGroupId),
        );
    }

    /**
     * @param array<string, mixed> $submitted
     */
    private function nullableIntFromSubmitted(array $submitted, string $key, ?int $current): ?int
    {
        if (!\array_key_exists($key, $submitted)) {
            return $current;
        }

        $value = $submitted[$key];
        if (null === $value || '' === $value) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $submitted
     */
    private function nullableStringFromSubmitted(array $submitted, string $key, ?string $current): ?string
    {
        if (!\array_key_exists($key, $submitted)) {
            return $current;
        }

        $value = $submitted[$key];
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $submitted
     */
    private function nullableBoolFromSubmitted(array $submitted, string $key, ?bool $current): ?bool
    {
        if (!\array_key_exists($key, $submitted)) {
            return $current;
        }

        $value = $submitted[$key];
        if (null === $value || '' === $value) {
            return null;
        }

        return (bool) (int) $value;
    }
}
