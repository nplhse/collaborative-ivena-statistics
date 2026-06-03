<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Allocation\Infrastructure\Repository\DispatchAreaRepository;
use App\Allocation\Infrastructure\Repository\StateRepository;
use App\Statistics\Application\Cohort\HospitalCohortEligibilityChecker;
use App\Statistics\Application\Cohort\HospitalCohortKey;
use App\Statistics\Application\Cohort\HospitalCohortLabelResolver;
use App\Statistics\Application\Cohort\HospitalCohortResolver;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Infrastructure\Query\AllocationStatsProjectionScopeQuery;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class AnalysisComparisonControlsFactory
{
    /** @var list<string> */
    private const array COMPARISON_SCOPE_REMOVE = [
        'comparison_cohort',
        'comparison_state',
        StatisticsQueryKeys::COMPARISON_DISPATCH_AREA,
    ];

    public function __construct(
        private StatisticsNavigationUrlBuilder $statisticsNavigationUrlBuilder,
        private StateRepository $stateRepository,
        private DispatchAreaRepository $dispatchAreaRepository,
        private AllocationStatsProjectionScopeQuery $projectionScopeQuery,
        private HospitalCohortResolver $hospitalCohortResolver,
        private HospitalCohortEligibilityChecker $hospitalCohortEligibilityChecker,
        private HospitalCohortLabelResolver $hospitalCohortLabelResolver,
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
                    self::COMPARISON_SCOPE_REMOVE,
                ),
                'active' => StatisticsFilterScope::Public === $comparisonFilter->scope,
            ],
        ];

        $eligibleStateIds = $this->projectionScopeQuery->stateIdsWithAtLeastDistinctHospitals(2);
        $stateRows = [];
        foreach ($eligibleStateIds as $stateId) {
            $state = $this->stateRepository->findById($stateId);
            $name = $state?->getName();
            if (null === $name || '' === $name) {
                continue;
            }
            $stateRows[] = ['id' => $stateId, 'name' => $name];
        }
        usort($stateRows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        foreach ($stateRows as $row) {
            $stateId = $row['id'];
            $stateKey = sprintf('state:%d', $stateId);
            $scopeChoices[$stateKey] = [
                'label' => $row['name'],
                'url' => $this->statisticsNavigationUrlBuilder->build(
                    $request,
                    'app_stats_analysis',
                    ['comparison_scope' => $stateKey],
                    self::COMPARISON_SCOPE_REMOVE,
                ),
                'active' => StatisticsFilterScope::State === $comparisonFilter->scope
                    && $comparisonFilter->stateId === $stateId,
            ];
        }

        $eligibleDispatchIds = $this->projectionScopeQuery->dispatchAreaIdsWithAtLeastDistinctHospitals(2);
        $dispatchRows = [];
        foreach ($eligibleDispatchIds as $dispatchAreaId) {
            $area = $this->dispatchAreaRepository->findById($dispatchAreaId);
            $name = $area?->getName();
            if (null === $name || '' === $name) {
                continue;
            }
            $dispatchRows[] = ['id' => $dispatchAreaId, 'name' => $name];
        }
        usort($dispatchRows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        foreach ($dispatchRows as $row) {
            $dispatchAreaId = $row['id'];
            $dispatchKey = StatisticsFilterScope::DispatchArea->value.':'.$dispatchAreaId;
            $scopeChoices[$dispatchKey] = [
                'label' => $row['name'],
                'url' => $this->statisticsNavigationUrlBuilder->build(
                    $request,
                    'app_stats_analysis',
                    ['comparison_scope' => $dispatchKey],
                    self::COMPARISON_SCOPE_REMOVE,
                ),
                'active' => StatisticsFilterScope::DispatchArea === $comparisonFilter->scope
                    && $comparisonFilter->dispatchAreaId === $dispatchAreaId,
            ];
        }

        foreach (HospitalCohortKey::all() as $cohortKey) {
            $cohort = $this->hospitalCohortResolver->resolve($cohortKey);
            if (!$this->hospitalCohortEligibilityChecker->hasMinimumParticipants($cohort)) {
                continue;
            }

            $scopeKey = StatisticsFilterScope::HospitalCohort->value.':'.$cohortKey->value();
            $scopeChoices[$scopeKey] = [
                'label' => $this->hospitalCohortLabelResolver->label($cohortKey, $request->getLocale()),
                'url' => $this->statisticsNavigationUrlBuilder->build(
                    $request,
                    'app_stats_analysis',
                    ['comparison_scope' => $scopeKey],
                    ['comparison_state', StatisticsQueryKeys::COMPARISON_DISPATCH_AREA],
                ),
                'active' => StatisticsFilterScope::HospitalCohort === $comparisonFilter->scope
                    && $comparisonFilter->cohortType instanceof HospitalCohortKey
                    && $comparisonFilter->cohortType->equals($cohortKey),
            ];
        }

        $activeScope = match ($comparisonFilter->scope) {
            StatisticsFilterScope::Public => StatisticsFilterScope::Public->value,
            StatisticsFilterScope::State => null !== $comparisonFilter->stateId
                ? sprintf('state:%d', $comparisonFilter->stateId)
                : StatisticsFilterScope::Public->value,
            StatisticsFilterScope::DispatchArea => null !== $comparisonFilter->dispatchAreaId
                ? StatisticsFilterScope::DispatchArea->value.':'.$comparisonFilter->dispatchAreaId
                : StatisticsFilterScope::Public->value,
            default => StatisticsFilterScope::HospitalCohort->value.':'.($comparisonFilter->cohortType ?? HospitalCohortKey::all()[0])->value(),
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
