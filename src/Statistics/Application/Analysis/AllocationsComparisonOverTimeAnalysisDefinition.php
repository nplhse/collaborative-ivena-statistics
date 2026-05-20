<?php

declare(strict_types=1);

namespace App\Statistics\Application\Analysis;

use App\Allocation\Infrastructure\Repository\DispatchAreaRepository;
use App\Allocation\Infrastructure\Repository\StateRepository;
use App\Statistics\Application\AllocationsByMonthQuery;
use App\Statistics\Application\DTO\StatisticsAnalysisDimension;
use App\Statistics\Application\DTO\StatisticsChartMeasure;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticWidget;
use App\Statistics\Application\DTO\StatisticWidgetType;
use App\Statistics\Application\DTO\WidgetPayload\SimpleChartWidgetPayload;
use App\Statistics\Application\DTO\WidgetPayload\TableWidgetPayload;
use App\Statistics\Application\DTO\WidgetPayload\WidgetPayloadNormalizer;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\Application\StatisticsHospitalScopeLabelResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class AllocationsComparisonOverTimeAnalysisDefinition implements AnalysisDefinitionInterface
{
    public function __construct(
        private AllocationsByMonthQuery $allocationsByMonthQuery,
        private StatisticsContextFactory $statisticsContextFactory,
        private WidgetPayloadNormalizer $widgetPayloadNormalizer,
        private TranslatorInterface $translator,
        private StateRepository $stateRepository,
        private DispatchAreaRepository $dispatchAreaRepository,
        private StatisticsHospitalScopeLabelResolver $hospitalScopeLabelResolver,
    ) {
    }

    #[\Override]
    public function key(): string
    {
        return 'allocations_comparison_over_time';
    }

    #[\Override]
    public function labelTranslationKey(): string
    {
        return 'stats.analysis.allocations_comparison_over_time.label';
    }

    #[\Override]
    public function supports(StatisticsContext $context): bool
    {
        return $context->comparisonFilter instanceof StatisticsFilter;
    }

    #[\Override]
    public function isPivotLike(): bool
    {
        return false;
    }

    #[\Override]
    public function build(
        StatisticsContext $context,
        string $view,
        string $chartType,
        StatisticsAnalysisDimension $dimension,
        StatisticsChartMeasure $chartMeasure = StatisticsChartMeasure::Absolute,
    ): StatisticWidget {
        unset($dimension, $chartMeasure);

        $primarySeries = $this->allocationsByMonthQuery->fetch($context, StatisticsAnalysisDimension::Total);
        $primaryLabel = sprintf(
            '%s (%s)',
            $this->scopeLabel($context->filter, $context->user),
            $this->periodLabel($context->filter),
        );
        $comparisonContext = $this->statisticsContextFactory->create(
            $context->user,
            $context->comparisonFilter ?? $context->filter,
        );
        $comparisonFilter = $context->comparisonFilter ?? $context->filter;
        $comparisonLabel = sprintf(
            '%s (%s)',
            $this->scopeLabel($comparisonFilter, $context->user),
            $this->periodLabel($comparisonFilter),
        );
        $comparisonSeries = $this->allocationsByMonthQuery->fetch($comparisonContext, StatisticsAnalysisDimension::Total);
        $primaryValues = $this->firstSegmentValues($primarySeries->labels, $primarySeries->segments);
        $comparisonValues = $this->firstSegmentValues($primarySeries->labels, $comparisonSeries->segments);

        if ('table' === $view) {
            return new StatisticWidget(
                StatisticWidgetType::Table,
                $this->key().'_table',
                $this->widgetPayloadNormalizer->normalize(
                    new TableWidgetPayload(
                        [
                            'stats.analysis.table.month',
                            $primaryLabel,
                            $comparisonLabel,
                            'stats.analysis.comparison.delta',
                            'stats.analysis.comparison.delta_percent',
                        ],
                        $this->buildRows($primarySeries->labels, $primaryValues, $comparisonValues),
                    )
                ),
            );
        }

        $resolvedChartType = 'line' === $chartType ? 'line' : 'bar';
        $chartPayload = [
            'series' => [
                [
                    'name' => $primaryLabel,
                    'data' => $primaryValues,
                ],
                [
                    'name' => $comparisonLabel,
                    'data' => $comparisonValues,
                ],
            ],
        ];
        if ('bar' === $resolvedChartType) {
            $chartPayload['barGrouped'] = true;
        }

        $payload = new SimpleChartWidgetPayload(
            $resolvedChartType,
            $primarySeries->labels,
            $chartPayload,
        );

        return new StatisticWidget(
            StatisticWidgetType::SimpleChart,
            $this->key().'_chart',
            $this->widgetPayloadNormalizer->normalize($payload),
        );
    }

    #[\Override]
    public function supportsDimensionSelector(): bool
    {
        return false;
    }

    #[\Override]
    public function supportsChartMeasureSelector(
        StatisticsAnalysisDimension $dimension,
        string $view,
        string $chartType,
    ): bool {
        return false;
    }

    /**
     * @param list<string> $labels
     * @param list<int>    $primaryValues
     * @param list<int>    $comparisonValues
     *
     * @return list<list<string|int|float>>
     */
    private function buildRows(array $labels, array $primaryValues, array $comparisonValues): array
    {
        $rows = [];
        foreach ($labels as $index => $label) {
            $primary = $primaryValues[$index] ?? 0;
            $comparison = $comparisonValues[$index] ?? 0;
            $delta = $primary - $comparison;
            $deltaPercent = $comparison > 0 ? round(((float) $delta / (float) $comparison) * 100.0, 1) : 0.0;

            $rows[] = [
                $label,
                $primary,
                $comparison,
                $delta,
                sprintf('%.1f%%', $deltaPercent),
            ];
        }

        return $rows;
    }

    /**
     * @param list<string>                                                           $labels
     * @param list<\App\Statistics\Application\DTO\AllocationsOverTimeSeriesSegment> $segments
     *
     * @return list<int>
     */
    private function firstSegmentValues(array $labels, array $segments): array
    {
        if ([] === $segments) {
            return array_fill(0, \count($labels), 0);
        }

        $values = $segments[0]->values;
        if (\count($values) >= \count($labels)) {
            return $values;
        }

        return array_pad($values, \count($labels), 0);
    }

    private function periodLabel(StatisticsFilter $filter): string
    {
        return match ($filter->period) {
            StatisticsFilterPeriod::All => $this->translator->trans('stats.filter.period.all'),
            StatisticsFilterPeriod::AllTime => $this->translator->trans('stats.filter.period.all_time'),
            StatisticsFilterPeriod::Year => (string) ($filter->referenceYear ?? (int) new \DateTimeImmutable()->format('Y')),
            StatisticsFilterPeriod::Month => sprintf(
                '%04d-%02d',
                $filter->referenceYear ?? (int) new \DateTimeImmutable()->format('Y'),
                $filter->referenceMonth ?? (int) new \DateTimeImmutable()->format('n'),
            ),
        };
    }

    private function scopeLabel(StatisticsFilter $filter, ?\App\User\Domain\Entity\User $user): string
    {
        return match ($filter->scope) {
            \App\Statistics\Application\DTO\StatisticsFilterScope::Public => $this->translator->trans('stats.filter.scope.public'),
            \App\Statistics\Application\DTO\StatisticsFilterScope::MyHospitals => $this->hospitalScopeLabelResolver->groupLabel($user),
            \App\Statistics\Application\DTO\StatisticsFilterScope::Hospital => null !== $filter->hospitalId
                ? sprintf('Hospital %d', $filter->hospitalId)
                : $this->translator->trans('stats.filter.hospital.choose'),
            \App\Statistics\Application\DTO\StatisticsFilterScope::HospitalCohort => $filter->cohortType instanceof \App\Statistics\Application\Cohort\HospitalCohortType
                ? $this->translator->trans($filter->cohortType->labelTranslationKey())
                : $this->translator->trans('stats.filter.scope.hospital_cohort'),
            \App\Statistics\Application\DTO\StatisticsFilterScope::State => $this->stateLabel($filter->stateId),
            \App\Statistics\Application\DTO\StatisticsFilterScope::DispatchArea => $this->dispatchAreaLabel($filter->dispatchAreaId),
        };
    }

    private function stateLabel(?int $stateId): string
    {
        if (null === $stateId) {
            return $this->translator->trans('stats.filter.scope.state');
        }
        $state = $this->stateRepository->findById($stateId);

        return $state?->getName() ?? sprintf('State %d', $stateId);
    }

    private function dispatchAreaLabel(?int $dispatchAreaId): string
    {
        if (null === $dispatchAreaId) {
            return $this->translator->trans('stats.filter.scope.dispatch_area');
        }
        $area = $this->dispatchAreaRepository->findById($dispatchAreaId);

        return $area?->getName() ?? sprintf('Dispatch area %d', $dispatchAreaId);
    }
}
