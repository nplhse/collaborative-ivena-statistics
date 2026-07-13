<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use App\Statistics\UI\Form\Data\StatisticsScopePeriodFormData;
use Symfony\Component\Form\FormInterface;

final readonly class ExplorerEditFormSubmittedDataFactory
{
    public function __construct(
        private ExplorerEditFormFilterFieldMapper $editFormFilterFieldMapper,
    ) {
    }

    /**
     * @param array<string, mixed>                $submitted
     * @param FormInterface<ExplorerEditFormData> $form
     */
    public function createFromSubmitted(
        ExplorerEditFormData $formData,
        array $submitted,
        FormInterface $form,
    ): ExplorerEditFormData {
        $scopePeriod = $this->resolveScopePeriodFormData($formData->scopePeriod, $submitted, $form);

        $base = new ExplorerEditFormData(
            scopePeriod: $scopePeriod,
            dataSource: \is_string($submitted['dataSource'] ?? null) ? $submitted['dataSource'] : $formData->dataSource,
            rowDimension: \is_string($submitted['rowDimension'] ?? null) ? $submitted['rowDimension'] : $formData->rowDimension,
            rowGrain: \array_key_exists('rowGrain', $submitted)
                ? (\is_string($submitted['rowGrain']) ? $submitted['rowGrain'] : null)
                : $formData->rowGrain,
            columnDimension: \array_key_exists('columnDimension', $submitted)
                ? (\is_string($submitted['columnDimension']) ? $submitted['columnDimension'] : null)
                : $formData->columnDimension,
            columnGrain: \array_key_exists('columnGrain', $submitted)
                ? (\is_string($submitted['columnGrain']) ? $submitted['columnGrain'] : null)
                : $formData->columnGrain,
            metric: \is_string($submitted['metric'] ?? null) ? $submitted['metric'] : $formData->metric,
            showPercentOfTotal: (bool) ($submitted['showPercentOfTotal'] ?? $formData->showPercentOfTotal),
            chartType: \is_string($submitted['chartType'] ?? null) ? $submitted['chartType'] : $formData->chartType,
            tableLayout: \is_string($submitted['tableLayout'] ?? null) ? $submitted['tableLayout'] : $formData->tableLayout,
            chartRowLimit: \is_string($submitted['chartRowLimit'] ?? null) ? $submitted['chartRowLimit'] : $formData->chartRowLimit,
            hospitalPopulation: \is_string($submitted['hospitalPopulation'] ?? null) ? $submitted['hospitalPopulation'] : $formData->hospitalPopulation,
            additionalTableMetrics: \array_key_exists('additionalTableMetrics', $submitted)
                ? array_values(array_filter(
                    \is_array($submitted['additionalTableMetrics']) ? $submitted['additionalTableMetrics'] : [],
                    static fn (mixed $value): bool => \is_string($value) && '' !== $value,
                ))
                : $formData->additionalTableMetrics,
            filterDepartmentId: $formData->filterDepartmentId,
            filterSpecialityId: $formData->filterSpecialityId,
            filterUrgency: $formData->filterUrgency,
            filterTransportType: $formData->filterTransportType,
            filterGender: $formData->filterGender,
            filterAgeGroup: $formData->filterAgeGroup,
            filterResus: $formData->filterResus,
            filterCpr: $formData->filterCpr,
            filterVentilation: $formData->filterVentilation,
            filterAssignmentId: $formData->filterAssignmentId,
            filterIndicationId: $formData->filterIndicationId,
            filterSecondaryIndicationId: $formData->filterSecondaryIndicationId,
            filterIndicationGroupId: $formData->filterIndicationGroupId,
        );

        return $this->editFormFilterFieldMapper->mergeSubmittedFilters($base, $submitted);
    }

    /**
     * @param array<string, mixed>                $submitted
     * @param FormInterface<ExplorerEditFormData> $form
     */
    public function resolveScopePeriodFormData(
        StatisticsScopePeriodFormData $fallback,
        array $submitted,
        FormInterface $form,
    ): StatisticsScopePeriodFormData {
        if (isset($submitted['scopePeriod']) && \is_array($submitted['scopePeriod'])) {
            $scopeSubmitted = $submitted['scopePeriod'];

            return new StatisticsScopePeriodFormData(
                (string) ($scopeSubmitted['scopeGroup'] ?? $fallback->scopeGroup),
                isset($scopeSubmitted['scopeDetail']) ? (string) $scopeSubmitted['scopeDetail'] : $fallback->scopeDetail,
                (string) ($scopeSubmitted['period'] ?? $fallback->period),
                isset($scopeSubmitted['periodYear']) && '' !== $scopeSubmitted['periodYear']
                    ? (int) $scopeSubmitted['periodYear']
                    : $fallback->periodYear,
                isset($scopeSubmitted['periodQuarter']) && '' !== $scopeSubmitted['periodQuarter']
                    ? (int) $scopeSubmitted['periodQuarter']
                    : $fallback->periodQuarter,
                isset($scopeSubmitted['periodMonth']) && '' !== $scopeSubmitted['periodMonth']
                    ? (int) $scopeSubmitted['periodMonth']
                    : $fallback->periodMonth,
            );
        }

        $scopePeriod = $form->get('scopePeriod')->getData();
        if ($scopePeriod instanceof StatisticsScopePeriodFormData) {
            return $scopePeriod;
        }

        return $fallback;
    }
}
