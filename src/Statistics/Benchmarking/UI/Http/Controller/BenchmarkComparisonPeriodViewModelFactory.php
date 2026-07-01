<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\StatisticsPeriodNavigation;
use App\Statistics\UI\Http\Controller\OverviewPeriodViewModel;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class BenchmarkComparisonPeriodViewModelFactory
{
    public function __construct(
        private StatisticsPeriodNavigation $periodNavigation,
        private StatisticsNavigationUrlBuilder $urlBuilder,
        private TranslatorInterface $translator,
    ) {
    }

    public function create(Request $request, string $routeName, StatisticsFilter $filter): OverviewPeriodViewModel
    {
        $locale = $request->getLocale();
        $now = new \DateTimeImmutable();

        $primaryMenu = $this->buildPrimaryMenu($request, $routeName, $filter, $locale, $now);
        [$secondaryMenu, $showSecondary] = $this->buildSecondaryMenu($request, $routeName, $filter, $locale);

        $showNavigation = $this->showsStepNavigation($filter);
        $previousFilter = $showNavigation ? $this->periodNavigation->previous($filter) : null;
        $nextFilter = $showNavigation ? $this->periodNavigation->next($filter) : null;

        return new OverviewPeriodViewModel(
            $this->periodLabel($filter, $locale),
            $this->primaryDropdownLabel($filter, $locale),
            $showSecondary ? $this->periodLabel($filter, $locale) : null,
            $showSecondary,
            $primaryMenu,
            $secondaryMenu,
            $previousFilter instanceof StatisticsFilter ? $this->urlForFilter($request, $routeName, $previousFilter) : null,
            $nextFilter instanceof StatisticsFilter ? $this->urlForFilter($request, $routeName, $nextFilter) : null,
            $previousFilter instanceof StatisticsFilter ? $this->periodLabel($previousFilter, $locale) : null,
            $nextFilter instanceof StatisticsFilter ? $this->periodLabel($nextFilter, $locale) : null,
            $showNavigation && $this->periodNavigation->isPreviousEnabled($filter),
            $showNavigation && $this->periodNavigation->isNextEnabled($filter),
            $showNavigation,
        );
    }

    /**
     * @return list<array{key: string, label: string, url: string, active: bool}>
     */
    private function buildPrimaryMenu(
        Request $request,
        string $routeName,
        StatisticsFilter $filter,
        string $locale,
        \DateTimeImmutable $now,
    ): array {
        $currentYear = (int) $now->format('Y');
        $currentMonth = (int) $now->format('n');
        $currentQuarter = (int) ceil($currentMonth / 3);

        /** @var list<array{0: StatisticsFilterPeriod, 1: string}> $modes */
        $modes = [
            [StatisticsFilterPeriod::AllTime, 'stats.filter.period.all_time'],
            [StatisticsFilterPeriod::All, 'stats.filter.period.all'],
            [StatisticsFilterPeriod::Year, 'stats.filter.period.year'],
            [StatisticsFilterPeriod::Quarter, 'stats.filter.period.quarter'],
            [StatisticsFilterPeriod::Month, 'stats.filter.period.month'],
        ];

        $items = [];
        foreach ($modes as [$period, $translationKey]) {
            $target = match ($period) {
                StatisticsFilterPeriod::All => $this->filterWithPeriod($filter, StatisticsFilterPeriod::All, null, null, null),
                StatisticsFilterPeriod::AllTime => $this->filterWithPeriod($filter, StatisticsFilterPeriod::AllTime, null, null, null),
                StatisticsFilterPeriod::Year => $this->filterWithPeriod($filter, StatisticsFilterPeriod::Year, $filter->referenceYear ?? $currentYear, null, null),
                StatisticsFilterPeriod::Quarter => $this->filterWithPeriod(
                    $filter,
                    StatisticsFilterPeriod::Quarter,
                    $filter->referenceYear ?? $currentYear,
                    null,
                    $filter->referenceQuarter ?? $currentQuarter,
                ),
                StatisticsFilterPeriod::Month => $this->filterWithPeriod(
                    $filter,
                    StatisticsFilterPeriod::Month,
                    $filter->referenceYear ?? $currentYear,
                    $filter->referenceMonth ?? $currentMonth,
                    null,
                ),
            };

            $items[] = [
                'key' => $period->value,
                'label' => $this->translator->trans($translationKey, [], 'statistics', $locale),
                'url' => $this->urlForFilter($request, $routeName, $target),
                'active' => $filter->period === $period,
            ];
        }

        return $items;
    }

    /**
     * @return array{0: list<array{label: string, url: string, active: bool, divider?: bool}>, 1: bool}
     */
    private function buildSecondaryMenu(
        Request $request,
        string $routeName,
        StatisticsFilter $filter,
        string $locale,
    ): array {
        return match ($filter->period) {
            StatisticsFilterPeriod::Year => [$this->buildYearSecondaryMenu($request, $routeName, $filter), true],
            StatisticsFilterPeriod::Quarter => [$this->buildQuarterSecondaryMenu($request, $routeName, $filter, $locale), true],
            StatisticsFilterPeriod::Month => [$this->buildMonthSecondaryMenu($request, $routeName, $filter, $locale), true],
            default => [[], false],
        };
    }

    /**
     * @return list<array{label: string, url: string, active: bool, divider?: bool}>
     */
    private function buildYearSecondaryMenu(Request $request, string $routeName, StatisticsFilter $filter): array
    {
        $referenceYear = $filter->referenceYear ?? (int) new \DateTimeImmutable()->format('Y');
        $items = [];

        for ($year = $this->periodNavigation->currentYear(); $year >= $this->periodNavigation->earliestYear(); --$year) {
            $target = $this->filterWithPeriod($filter, StatisticsFilterPeriod::Year, $year, null, null);
            $items[] = [
                'label' => (string) $year,
                'url' => $this->urlForFilter($request, $routeName, $target),
                'active' => $referenceYear === $year,
            ];
        }

        return $items;
    }

    /**
     * @return list<array{label: string, url: string, active: bool, divider?: bool}>
     */
    private function buildQuarterSecondaryMenu(Request $request, string $routeName, StatisticsFilter $filter, string $locale): array
    {
        $referenceYear = $filter->referenceYear ?? (int) new \DateTimeImmutable()->format('Y');
        $referenceQuarter = $filter->referenceQuarter ?? 1;
        $items = [];

        for ($quarter = 1; $quarter <= 4; ++$quarter) {
            $target = $this->filterWithPeriod($filter, StatisticsFilterPeriod::Quarter, $referenceYear, null, $quarter);
            $items[] = [
                'label' => $this->translator->trans('stats.dashboard.heading.quarter', [
                    'quarter' => (string) $quarter,
                    'year' => (string) $referenceYear,
                ], 'statistics', $locale),
                'url' => $this->urlForFilter($request, $routeName, $target),
                'active' => $referenceQuarter === $quarter,
            ];
        }

        return $items;
    }

    /**
     * @return list<array{label: string, url: string, active: bool, divider?: bool}>
     */
    private function buildMonthSecondaryMenu(Request $request, string $routeName, StatisticsFilter $filter, string $locale): array
    {
        $referenceYear = $filter->referenceYear ?? (int) new \DateTimeImmutable()->format('Y');
        $referenceMonth = $filter->referenceMonth ?? 1;
        $items = [];

        for ($month = 1; $month <= 12; ++$month) {
            $target = $this->filterWithPeriod($filter, StatisticsFilterPeriod::Month, $referenceYear, $month, null);
            $items[] = [
                'label' => $this->monthLabel($referenceYear, $month, $locale),
                'url' => $this->urlForFilter($request, $routeName, $target),
                'active' => $referenceMonth === $month,
            ];
        }

        return $items;
    }

    private function urlForFilter(Request $request, string $routeName, StatisticsFilter $filter): string
    {
        return $this->urlBuilder->build(
            $request,
            $routeName,
            $this->periodReplaceParams($filter),
            $this->periodRemoveKeys($filter),
        );
    }

    /**
     * @return array<string, scalar>
     */
    private function periodReplaceParams(StatisticsFilter $filter): array
    {
        $params = [StatisticsQueryKeys::COMPARISON_PERIOD => $filter->period->value];

        if (null !== $filter->referenceYear) {
            $params[StatisticsQueryKeys::COMPARISON_YEAR] = $filter->referenceYear;
        }
        if (null !== $filter->referenceMonth) {
            $params[StatisticsQueryKeys::COMPARISON_MONTH] = $filter->referenceMonth;
        }
        if (null !== $filter->referenceQuarter) {
            $params[StatisticsQueryKeys::COMPARISON_QUARTER] = $filter->referenceQuarter;
        }

        return $params;
    }

    /**
     * @return list<string>
     */
    private function periodRemoveKeys(StatisticsFilter $filter): array
    {
        return match ($filter->period) {
            StatisticsFilterPeriod::AllTime, StatisticsFilterPeriod::All => StatisticsQueryKeys::REMOVE_COMPARISON_PERIOD_DEPENDENT,
            StatisticsFilterPeriod::Year => StatisticsQueryKeys::REMOVE_COMPARISON_QUARTER_DEPENDENT,
            StatisticsFilterPeriod::Quarter => StatisticsQueryKeys::REMOVE_COMPARISON_MONTH_DEPENDENT,
            StatisticsFilterPeriod::Month => [StatisticsQueryKeys::COMPARISON_QUARTER],
        };
    }

    private function filterWithPeriod(
        StatisticsFilter $filter,
        StatisticsFilterPeriod $period,
        ?int $year,
        ?int $month,
        ?int $quarter,
    ): StatisticsFilter {
        return new StatisticsFilter(
            $filter->scope,
            $filter->hospitalId,
            $filter->cohortType,
            $period,
            $year,
            $month,
            $quarter,
            $filter->notice,
            false,
            $filter->stateId,
            $filter->dispatchAreaId,
        );
    }

    private function showsStepNavigation(StatisticsFilter $filter): bool
    {
        return !\in_array($filter->period, [StatisticsFilterPeriod::AllTime, StatisticsFilterPeriod::All], true);
    }

    private function periodLabel(StatisticsFilter $filter, string $locale): string
    {
        $now = new \DateTimeImmutable();

        return match ($filter->period) {
            StatisticsFilterPeriod::All => $this->translator->trans('stats.filter.period.all', [], 'statistics', $locale),
            StatisticsFilterPeriod::AllTime => $this->translator->trans('stats.filter.period.all_time', [], 'statistics', $locale),
            StatisticsFilterPeriod::Year => (string) ($filter->referenceYear ?? $now->format('Y')),
            StatisticsFilterPeriod::Quarter => $this->translator->trans(
                'stats.dashboard.heading.quarter',
                [
                    'quarter' => (string) ($filter->referenceQuarter ?? (int) ceil((int) $now->format('n') / 3)),
                    'year' => (string) ($filter->referenceYear ?? $now->format('Y')),
                ],
                'statistics',
                $locale,
            ),
            StatisticsFilterPeriod::Month => $this->monthLabel(
                $filter->referenceYear ?? (int) $now->format('Y'),
                $filter->referenceMonth ?? (int) $now->format('n'),
                $locale,
            ),
        };
    }

    private function primaryDropdownLabel(StatisticsFilter $filter, string $locale): string
    {
        return match ($filter->period) {
            StatisticsFilterPeriod::All => $this->translator->trans('stats.filter.period.all', [], 'statistics', $locale),
            StatisticsFilterPeriod::AllTime => $this->translator->trans('stats.filter.period.all_time', [], 'statistics', $locale),
            StatisticsFilterPeriod::Year => $this->translator->trans('stats.filter.period.year', [], 'statistics', $locale),
            StatisticsFilterPeriod::Quarter => $this->translator->trans('stats.filter.period.quarter', [], 'statistics', $locale),
            StatisticsFilterPeriod::Month => $this->translator->trans('stats.filter.period.month', [], 'statistics', $locale),
        };
    }

    private function monthLabel(int $year, int $month, string $locale): string
    {
        $month = max(1, min(12, $month));
        $midMonth = new \DateTimeImmutable(sprintf('%04d-%02d-15 12:00:00', $year, $month));
        $formatted = \IntlDateFormatter::formatObject($midMonth, 'LLLL yyyy', $locale);
        if (false !== $formatted && '' !== $formatted) {
            return $formatted;
        }

        return sprintf('%04d-%02d', $year, $month);
    }
}
