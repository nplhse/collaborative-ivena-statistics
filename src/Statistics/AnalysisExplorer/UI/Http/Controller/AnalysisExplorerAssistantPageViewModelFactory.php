<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Http\Controller;

use App\Statistics\AnalysisExplorer\Application\AnalysisFilterChoiceProvider;
use App\Statistics\AnalysisExplorer\Application\ExplorerAnalysisSummaryLabelResolverInterface;
use App\Statistics\AnalysisExplorer\Application\ExplorerAssistantConfigFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerAssistantConfigResolver;
use App\Statistics\AnalysisExplorer\Application\ExplorerAssistantFilters;
use App\Statistics\AnalysisExplorer\Application\ExplorerStatisticsFilterInputFactory;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerAssistantGoal;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerAssistantReadiness;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerAssistantDraft;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\UI\Form\Data\StatisticsScopePeriodFormData;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use App\User\Domain\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class AnalysisExplorerAssistantPageViewModelFactory
{
    private const array STEP_IDS = ['goal', 'source', 'context', 'questions', 'filters', 'summary'];

    public function __construct(
        private ExplorerAssistantConfigFactory $configFactory,
        private ExplorerAssistantConfigResolver $configResolver,
        private ExplorerStatisticsFilterInputFactory $filterInputFactory,
        private AnalysisFilterChoiceProvider $filterChoiceProvider,
        private ExplorerAnalysisSummaryLabelResolverInterface $summaryLabelResolver,
        private Security $security,
        private UrlGeneratorInterface $router,
        private TranslatorInterface $translator,
    ) {
    }

    public function create(StatisticsFilter $filter, ExplorerAssistantDraft $draft): AnalysisExplorerAssistantPageViewModel
    {
        $query = $draft->toQuery();
        $config = ExplorerAssistantReadiness::Ready === $this->configFactory->status($query)
            ? $this->configResolver->resolve($query, $filter)
            : null;
        $goal = $draft->goal;
        $scopeQuery = $this->scopeQueryFromSide($draft->scopePeriod);

        return new AnalysisExplorerAssistantPageViewModel(
            step: $draft->currentStep,
            goal: $goal?->value,
            libraryUrl: $this->router->generate('app_stats_analysis_library', $scopeQuery),
            formAction: $this->router->generate('app_stats_analysis_assistant'),
            cancelUrl: $this->router->generate('app_stats_analysis_assistant_cancel', $scopeQuery),
            steps: $this->steps($draft),
            stepDescription: $this->translator->trans(
                'stats.analysis_explorer.assistant.step_lead.'.$draft->currentStep,
                [],
                'statistics',
            ),
            goalTitle: $goal instanceof ExplorerAssistantGoal ? $this->goalTitle($goal) : null,
            summaryTitle: $config?->title,
            summaryPresentation: $config instanceof AnalysisViewConfig && $goal instanceof ExplorerAssistantGoal
                ? $this->presentation($draft)
                : null,
            summaryLines: $this->summaryLines($draft, $filter, $config),
        );
    }

    /**
     * @return list<AnalysisExplorerAssistantStepViewModel>
     */
    private function steps(ExplorerAssistantDraft $draft): array
    {
        $ids = self::STEP_IDS;
        if (AnalysisDataSourceKey::Hospitals === $draft->dataSource) {
            $ids = array_values(array_filter(
                $ids,
                static fn (string $id): bool => 'context' !== $id,
            ));
        }

        $currentIndex = array_search($draft->currentStep, $ids, true);
        $hospitals = AnalysisDataSourceKey::Hospitals === $draft->dataSource;
        $steps = [];
        foreach ($ids as $index => $id) {
            $state = 'upcoming';
            if ('filters' === $id && $hospitals) {
                $state = 'disabled';
            } elseif ($index === $currentIndex) {
                $state = 'current';
            } elseif (\is_int($currentIndex) && $index < $currentIndex) {
                $state = 'complete';
            }

            $steps[] = new AnalysisExplorerAssistantStepViewModel(
                $id,
                $this->translator->trans('stats.analysis_explorer.assistant.step_name.'.$id, [], 'statistics'),
                $state,
            );
        }

        return $steps;
    }

    /**
     * @return array<string, string>
     */
    public function scopeQuery(Request $request): array
    {
        $query = [];
        foreach ([
            StatisticsQueryKeys::SCOPE,
            StatisticsQueryKeys::HOSPITAL,
            StatisticsQueryKeys::COHORT,
            StatisticsQueryKeys::STATE,
            StatisticsQueryKeys::DISPATCH_AREA,
            StatisticsQueryKeys::PERIOD,
            StatisticsQueryKeys::YEAR,
            StatisticsQueryKeys::MONTH,
            StatisticsQueryKeys::QUARTER,
        ] as $key) {
            if ($request->query->has($key)) {
                $query[$key] = $request->query->getString($key);
            }
        }

        return $query;
    }

    /**
     * @return array<string, string>
     */
    public function scopeQueryFromSide(StatisticsScopePeriodFormData $side): array
    {
        $input = $this->filterInputFactory->fromSideFormData($side);
        $query = [
            StatisticsQueryKeys::SCOPE => $input->scope,
            StatisticsQueryKeys::PERIOD => $input->period,
        ];
        foreach ([
            StatisticsQueryKeys::HOSPITAL => $input->hospital,
            StatisticsQueryKeys::COHORT => $input->cohort,
            StatisticsQueryKeys::STATE => $input->state,
            StatisticsQueryKeys::DISPATCH_AREA => $input->dispatchArea,
        ] as $key => $value) {
            if ('' !== $value) {
                $query[$key] = $value;
            }
        }

        if (\in_array($input->period, [StatisticsFilterPeriod::Year->value, StatisticsFilterPeriod::Quarter->value, StatisticsFilterPeriod::Month->value], true)) {
            $year = $this->queryScalar($input->year);
            if (null !== $year) {
                $query[StatisticsQueryKeys::YEAR] = $year;
            }
        }
        if (StatisticsFilterPeriod::Month->value === $input->period) {
            $month = $this->queryScalar($input->month);
            if (null !== $month) {
                $query[StatisticsQueryKeys::MONTH] = $month;
            }
        }
        if (StatisticsFilterPeriod::Quarter->value === $input->period) {
            $quarter = $this->queryScalar($input->quarter);
            if (null !== $quarter) {
                $query[StatisticsQueryKeys::QUARTER] = $quarter;
            }
        }

        return $query;
    }

    private function queryScalar(mixed $value): ?string
    {
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        return $value;
    }

    /**
     * @return list<AnalysisExplorerAssistantSummaryLineViewModel>
     */
    private function summaryLines(ExplorerAssistantDraft $draft, StatisticsFilter $filter, ?AnalysisViewConfig $config): array
    {
        if ('summary' !== $draft->currentStep) {
            return [];
        }

        $user = $this->security->getUser();
        $locale = $this->translator->getLocale();
        $none = $this->translator->trans('stats.analysis_explorer.assistant.summary.none', [], 'statistics');
        $row = $this->dimensionLabel($draft->row);
        if ($draft->row?->isTemporalPrimary() && $draft->grain instanceof AnalysisDimensionGrain) {
            $row .= ' ('.$this->dimensionLabelFromValue($draft->grain->value).')';
        }
        $column = $draft->column instanceof AnalysisDimensionKey
            ? $this->dimensionLabel($draft->column)
            : $none;
        if ($draft->column?->isTemporalPrimary() && $draft->columnGrain instanceof AnalysisDimensionGrain) {
            $column .= ' ('.$this->dimensionLabelFromValue($draft->columnGrain->value).')';
        }
        $chart = $config instanceof AnalysisViewConfig
            ? $this->translator->trans('stats.analysis_explorer.chart.'.$config->presentation->chartType->value, [], 'statistics')
            : $none;

        $rows = [
            ['stats.analysis_explorer.assistant.step_name.goal', $draft->goal instanceof ExplorerAssistantGoal ? $this->goalTitle($draft->goal) : $none, 'goal'],
            ['stats.analysis_explorer.assistant.step_name.source', $this->translator->trans($draft->dataSource->labelTranslationKey(), [], 'statistics'), 'source'],
        ];
        if (AnalysisDataSourceKey::Allocations === $draft->dataSource) {
            $rows[] = ['stats.filter.scope_label', $this->summaryLabelResolver->scopeLabel($filter, $user instanceof User ? $user : null, $locale), 'context'];
            $rows[] = ['stats.filter.period_label', $this->summaryLabelResolver->periodLabel($filter, $locale), 'context'];
        }
        $rows[] = ['stats.analysis_explorer.assistant.rows.label', '' !== $row ? $row : $none, 'questions'];
        if (ExplorerAssistantGoal::Matrix === $draft->goal) {
            $rows[] = ['stats.analysis_explorer.assistant.columns.label', $column, 'questions'];
        }
        $rows = [
            ...$rows,
            ['stats.analysis_explorer.assistant.metric.label', $this->metricLabel($draft->metric), 'questions'],
            ['stats.analysis_explorer.edit.section.presentation', $chart, 'questions'],
        ];
        if (AnalysisDataSourceKey::Allocations === $draft->dataSource) {
            $rows[] = ['stats.analysis_explorer.assistant.step_name.filters', $this->filtersLabel($draft->toQuery()->filters) ?? $none, 'filters'];
        }

        $lines = [];
        $seen = [];
        foreach ($rows as [$labelKey, $value, $step]) {
            $showEdit = !isset($seen[$step]);
            $seen[$step] = true;
            $lines[] = new AnalysisExplorerAssistantSummaryLineViewModel(
                $this->translator->trans($labelKey, [], 'statistics'),
                $value,
                $step,
                $showEdit,
            );
        }

        return $lines;
    }

    private function dimensionLabelFromValue(string $value): string
    {
        return $this->translator->trans('stats.analysis_explorer.dimension.'.$value, [], 'statistics');
    }

    private function presentation(ExplorerAssistantDraft $draft): string
    {
        $goal = $draft->goal;
        if (!$goal instanceof ExplorerAssistantGoal) {
            return '';
        }

        $hasColumn = $draft->column instanceof AnalysisDimensionKey;
        $chartKey = match (true) {
            $draft->metric->isDistributionProfile() => 'box_plot',
            AnalysisDataSourceKey::Hospitals === $draft->dataSource && $hasColumn => 'matrix',
            AnalysisDataSourceKey::Hospitals === $draft->dataSource => 'distribution',
            ExplorerAssistantGoal::TimeSeries === $goal => 'time_series',
            ExplorerAssistantGoal::Toplist === $goal && !$hasColumn => 'toplist',
            $hasColumn => 'matrix',
            default => 'distribution',
        };
        $chart = $this->translator->trans(
            'stats.analysis_explorer.assistant.presentation.'.$chartKey,
            [],
            'statistics',
        );
        $columnPart = $hasColumn
            ? $this->translator->trans(
                'stats.analysis_explorer.assistant.presentation.column_part',
                ['column' => $this->dimensionLabel($draft->column)],
                'statistics',
            )
            : '';
        $filtersLabel = $this->filtersLabel($draft->toQuery()->filters);
        $filtersPart = null === $filtersLabel
            ? ''
            : $this->translator->trans(
                'stats.analysis_explorer.assistant.presentation.filters_part',
                ['filters' => $filtersLabel],
                'statistics',
            );

        return $chart.' '.$this->translator->trans(
            'stats.analysis_explorer.assistant.presentation.details',
            [
                'metric' => $this->metricLabel($draft->metric),
                'column' => $columnPart,
                'filters' => $filtersPart,
            ],
            'statistics',
        );
    }

    private function metricLabel(AnalysisMetricKey $metric): string
    {
        $key = $metric->isDistributionProfile()
            ? 'stats.analysis_explorer.metric_profile.'.$metric->value
            : 'stats.analysis_explorer.metric.'.$metric->value;

        return $this->translator->trans($key, [], 'statistics');
    }

    private function dimensionLabel(?AnalysisDimensionKey $dimension): string
    {
        if (!$dimension instanceof AnalysisDimensionKey) {
            return '';
        }

        return $this->translator->trans(
            'stats.analysis_explorer.dimension.'.$dimension->value,
            [],
            'statistics',
        );
    }

    private function filtersLabel(ExplorerAssistantFilters $filters): ?string
    {
        $parts = [];
        foreach ($filters->parameters() as $dimension => $value) {
            $parts[] = $this->translator->trans($this->filterLabelKey($dimension), [], 'messages')
                .': '.$this->filterValueLabel($dimension, $value);
        }

        if ([] === $parts) {
            return null;
        }

        return implode(', ', $parts);
    }

    private function filterLabelKey(string $dimension): string
    {
        return match ($dimension) {
            'resus' => 'label.requires_resus',
            'cpr' => 'label.is_cpr',
            'ventilation' => 'label.is_ventilated',
            default => 'label.'.$dimension,
        };
    }

    private function filterValueLabel(string $dimension, string $value): string
    {
        $choices = match ($dimension) {
            'department' => $this->filterChoiceProvider->departmentChoices(),
            'speciality' => $this->filterChoiceProvider->specialityChoices(),
            'urgency' => $this->filterChoiceProvider->urgencyChoices(),
            'transport_type' => $this->filterChoiceProvider->transportTypeChoices(),
            'gender' => $this->filterChoiceProvider->genderChoices(),
            'age_group' => $this->filterChoiceProvider->ageGroupChoices(),
            'assignment' => $this->filterChoiceProvider->assignmentChoices(),
            'indication', 'secondary_indication' => $this->filterChoiceProvider->indicationChoices(),
            'indication_group' => $this->filterChoiceProvider->indicationGroupChoices(),
            'resus', 'cpr', 'ventilation' => [
                1 => $this->translator->trans('label.yes', [], 'messages'),
                0 => $this->translator->trans('label.no', [], 'messages'),
            ],
            default => [],
        };

        if (\in_array($dimension, ['resus', 'cpr', 'ventilation'], true)) {
            return $choices['1' === $value ? 1 : 0] ?? $value;
        }

        if ('age_group' === $dimension) {
            return $choices[$value] ?? $value;
        }

        return $choices[(int) $value] ?? $value;
    }

    private function goalTitle(ExplorerAssistantGoal $goal): string
    {
        return $this->translator->trans(
            'stats.analysis_explorer.assistant.goal.'.$goal->value.'.title',
            [],
            'statistics',
        );
    }
}
