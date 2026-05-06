<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Allocation\Infrastructure\Repository\StateRepository;
use App\Statistics\Application\Cohort\HospitalCohortType;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class AnalysisComparisonControlsFactory
{
    public function __construct(
        private StatisticsNavigationUrlBuilder $statisticsNavigationUrlBuilder,
        private ?StateRepository $stateRepository,
        private TranslatorInterface $translator,
    ) {
    }

    public function build(Request $request, string $analysisKey, StatisticsFilter $comparisonFilter): AnalysisComparisonControlsViewModel
    {
        if ('allocations_comparison_over_time' !== $analysisKey) {
            return new AnalysisComparisonControlsViewModel(false, [], '', '', [], '');
        }

        $scopeChoices = [
            'public' => [
                'label' => $this->translator->trans('stats.filter.scope.public'),
                'url' => $this->statisticsNavigationUrlBuilder->build(
                    $request,
                    'app_stats_analysis',
                    ['comparison_scope' => StatisticsFilterScope::Public->value],
                    ['comparison_cohort', 'comparison_state'],
                ),
                'active' => StatisticsFilterScope::Public === $comparisonFilter->scope,
            ],
        ];
        if ($this->stateRepository instanceof StateRepository) {
            foreach ($this->stateRepository->findBy([], ['name' => 'ASC']) as $state) {
                $stateId = $state->getId();
                $stateName = $state->getName();
                if (null === $stateId || null === $stateName) {
                    continue;
                }
                $stateKey = sprintf('state:%d', $stateId);
                $scopeChoices[$stateKey] = [
                    'label' => $stateName,
                    'url' => $this->statisticsNavigationUrlBuilder->build(
                        $request,
                        'app_stats_analysis',
                        ['comparison_scope' => $stateKey],
                        ['comparison_cohort', 'comparison_state'],
                    ),
                    'active' => StatisticsFilterScope::State === $comparisonFilter->scope
                        && $comparisonFilter->stateId === $stateId,
                ];
            }
        }
        foreach (HospitalCohortType::cases() as $cohortType) {
            $scopeKey = StatisticsFilterScope::HospitalCohort->value.':'.$cohortType->value;
            $scopeChoices[$scopeKey] = [
                'label' => $this->translator->trans($cohortType->labelTranslationKey()),
                'url' => $this->statisticsNavigationUrlBuilder->build(
                    $request,
                    'app_stats_analysis',
                    ['comparison_scope' => $scopeKey],
                    ['comparison_state'],
                ),
                'active' => StatisticsFilterScope::HospitalCohort === $comparisonFilter->scope
                    && $comparisonFilter->cohortType === $cohortType,
            ];
        }
        $activeScope = match ($comparisonFilter->scope) {
            StatisticsFilterScope::Public => StatisticsFilterScope::Public->value,
            StatisticsFilterScope::State => null !== $comparisonFilter->stateId ? sprintf('state:%d', $comparisonFilter->stateId) : StatisticsFilterScope::Public->value,
            default => StatisticsFilterScope::HospitalCohort->value.':'.($comparisonFilter->cohortType ?? HospitalCohortType::cases()[0])->value,
        };
        $activeScopeLabel = $scopeChoices[$activeScope]['label'] ?? $this->translator->trans('stats.filter.scope.public');
        $periodChoices = $this->periodChoices($request, $comparisonFilter);
        $activePeriodLabel = $this->translator->trans('stats.analysis.comparison.choose_period');
        foreach ($periodChoices as $periodChoice) {
            if (true === $periodChoice['active']) {
                $activePeriodLabel = $periodChoice['label'];
                break;
            }
        }

        return new AnalysisComparisonControlsViewModel(
            true,
            $scopeChoices,
            $activeScope,
            $activeScopeLabel,
            $periodChoices,
            $activePeriodLabel,
        );
    }

    /**
     * @return array<string, array{label: string, url: string, active: bool}>
     */
    private function periodChoices(Request $request, StatisticsFilter $comparisonFilter): array
    {
        $defaultYear = $comparisonFilter->referenceYear ?? (int) new \DateTimeImmutable()->format('Y');
        $defaultMonth = $comparisonFilter->referenceMonth ?? (int) new \DateTimeImmutable()->format('n');

        return [
            'all' => [
                'label' => $this->translator->trans('stats.filter.period.all'),
                'url' => $this->statisticsNavigationUrlBuilder->build(
                    $request,
                    'app_stats_analysis',
                    ['comparison_period' => StatisticsFilterPeriod::All->value],
                    ['comparison_year', 'comparison_month'],
                ),
                'active' => StatisticsFilterPeriod::All === $comparisonFilter->period,
            ],
            'all_time' => [
                'label' => $this->translator->trans('stats.filter.period.all_time'),
                'url' => $this->statisticsNavigationUrlBuilder->build(
                    $request,
                    'app_stats_analysis',
                    ['comparison_period' => StatisticsFilterPeriod::AllTime->value],
                    ['comparison_year', 'comparison_month'],
                ),
                'active' => StatisticsFilterPeriod::AllTime === $comparisonFilter->period,
            ],
            'year' => [
                'label' => $this->translator->trans('stats.filter.period.year').' '.$defaultYear,
                'url' => $this->statisticsNavigationUrlBuilder->build(
                    $request,
                    'app_stats_analysis',
                    [
                        'comparison_period' => StatisticsFilterPeriod::Year->value,
                        'comparison_year' => $defaultYear,
                    ],
                    ['comparison_month'],
                ),
                'active' => StatisticsFilterPeriod::Year === $comparisonFilter->period,
            ],
            'month' => [
                'label' => $this->translator->trans('stats.filter.period.month').' '.sprintf('%04d-%02d', $defaultYear, $defaultMonth),
                'url' => $this->statisticsNavigationUrlBuilder->build(
                    $request,
                    'app_stats_analysis',
                    [
                        'comparison_period' => StatisticsFilterPeriod::Month->value,
                        'comparison_year' => $defaultYear,
                        'comparison_month' => $defaultMonth,
                    ],
                ),
                'active' => StatisticsFilterPeriod::Month === $comparisonFilter->period,
            ],
        ];
    }
}
