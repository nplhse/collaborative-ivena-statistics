<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\ExplorerAnalysisFilterCatalog;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisFilter;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisFilterOperator;

final readonly class ExplorerAnalysisFilterMapper
{
    /**
     * @return list<AnalysisFilter>
     */
    public function fromFormData(ExplorerEditFormData $formData): array
    {
        $filters = [];

        if ([] !== $formData->filterDepartmentIds) {
            $filters[] = new AnalysisFilter('department', AnalysisFilterOperator::In, $formData->filterDepartmentIds);
        }

        if ([] !== $formData->filterSpecialityIds) {
            $filters[] = new AnalysisFilter('speciality', AnalysisFilterOperator::In, $formData->filterSpecialityIds);
        }

        if (null !== $formData->filterUrgency) {
            $filters[] = new AnalysisFilter('urgency', AnalysisFilterOperator::Equals, $formData->filterUrgency);
        }

        if (null !== $formData->filterTransportType) {
            $filters[] = new AnalysisFilter('transport_type', AnalysisFilterOperator::Equals, $formData->filterTransportType);
        }

        if (null !== $formData->filterGender) {
            $filters[] = new AnalysisFilter('gender', AnalysisFilterOperator::Equals, $formData->filterGender);
        }

        if (null !== $formData->filterAgeGroup && '' !== $formData->filterAgeGroup) {
            $filters[] = new AnalysisFilter('age_group', AnalysisFilterOperator::Equals, $formData->filterAgeGroup);
        }

        if (null !== $formData->filterResus) {
            $filters[] = new AnalysisFilter('resus', AnalysisFilterOperator::Equals, $formData->filterResus ? 1 : 0);
        }

        if (null !== $formData->filterCpr) {
            $filters[] = new AnalysisFilter('cpr', AnalysisFilterOperator::Equals, $formData->filterCpr ? 1 : 0);
        }

        if (null !== $formData->filterVentilation) {
            $filters[] = new AnalysisFilter('ventilation', AnalysisFilterOperator::Equals, $formData->filterVentilation ? 1 : 0);
        }

        if (null !== $formData->filterAssignmentId) {
            $filters[] = new AnalysisFilter('assignment', AnalysisFilterOperator::Equals, $formData->filterAssignmentId);
        }

        return $this->sanitize($filters);
    }

    /**
     * @param list<AnalysisFilter> $filters
     */
    public function applyToFormData(ExplorerEditFormData $formData, array $filters): ExplorerEditFormData
    {
        $formData->filterDepartmentIds = [];
        $formData->filterSpecialityIds = [];
        $formData->filterUrgency = null;
        $formData->filterTransportType = null;
        $formData->filterGender = null;
        $formData->filterAgeGroup = null;
        $formData->filterResus = null;
        $formData->filterCpr = null;
        $formData->filterVentilation = null;
        $formData->filterAssignmentId = null;

        foreach ($this->sanitize($filters) as $filter) {
            match ($filter->dimensionKey) {
                'department' => $formData->filterDepartmentIds = $this->intList($filter->value),
                'speciality' => $formData->filterSpecialityIds = $this->intList($filter->value),
                'urgency' => $formData->filterUrgency = $this->intValue($filter->value),
                'transport_type' => $formData->filterTransportType = $this->intValue($filter->value),
                'gender' => $formData->filterGender = $this->intValue($filter->value),
                'age_group' => $formData->filterAgeGroup = $this->stringValue($filter->value),
                'resus' => $formData->filterResus = 1 === $this->intValue($filter->value),
                'cpr' => $formData->filterCpr = 1 === $this->intValue($filter->value),
                'ventilation' => $formData->filterVentilation = 1 === $this->intValue($filter->value),
                'assignment' => $formData->filterAssignmentId = $this->intValue($filter->value),
                default => null,
            };
        }

        return $formData;
    }

    /**
     * @param list<AnalysisFilter> $filters
     *
     * @return list<array{dimensionKey: string, operator: string, value: mixed}>
     */
    public function toStateArray(array $filters): array
    {
        return array_map(
            static fn (AnalysisFilter $filter): array => [
                'dimensionKey' => $filter->dimensionKey,
                'operator' => $filter->operator->value,
                'value' => $filter->value,
            ],
            $this->sanitize($filters),
        );
    }

    /**
     * @param list<mixed> $state
     *
     * @return list<AnalysisFilter>
     */
    public function fromStateArray(array $state): array
    {
        $filters = [];
        foreach ($state as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $dimensionKey = (string) ($row['dimensionKey'] ?? '');
            if (!ExplorerAnalysisFilterCatalog::isAllowed($dimensionKey)) {
                continue;
            }

            $operator = AnalysisFilterOperator::tryFrom((string) ($row['operator'] ?? ''))
                ?? AnalysisFilterOperator::Equals;

            $filters[] = new AnalysisFilter(
                dimensionKey: $dimensionKey,
                operator: $operator,
                value: $row['value'] ?? '',
            );
        }

        return $this->sanitize($filters);
    }

    /**
     * @param list<AnalysisFilter> $filters
     *
     * @return list<AnalysisFilter>
     */
    private function sanitize(array $filters): array
    {
        return array_values(array_filter(
            $filters,
            static fn (AnalysisFilter $filter): bool => ExplorerAnalysisFilterCatalog::isAllowed($filter->dimensionKey),
        ));
    }

    /**
     * @param int|string|bool|list<int|string|bool> $value
     *
     * @return list<int>
     */
    private function intList(int|string|bool|array $value): array
    {
        if (!\is_array($value)) {
            $int = $this->intValue($value);

            return null === $int ? [] : [$int];
        }

        $ids = [];
        foreach ($value as $item) {
            $int = $this->intValue($item);
            if (null !== $int) {
                $ids[] = $int;
            }
        }

        return $ids;
    }

    /**
     * @param int|string|bool|array<mixed> $value
     */
    private function intValue(int|string|bool|array $value): ?int
    {
        if (\is_array($value)) {
            return null;
        }

        if (\is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (\is_int($value)) {
            return $value;
        }

        if ('' === $value || !ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param int|string|bool|array<mixed> $value
     */
    private function stringValue(int|string|bool|array $value): ?string
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        return $value;
    }
}
