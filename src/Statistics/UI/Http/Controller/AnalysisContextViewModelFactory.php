<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\UI\Application\StatisticsFilterFormChoiceProvider;
use App\Statistics\UI\Application\StatisticsFilterSide;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use App\User\Domain\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class AnalysisContextViewModelFactory
{
    public function __construct(
        private StatisticsFilterFormChoiceProvider $choiceProvider,
        private HospitalAccessInterface $hospitalAccess,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {
    }

    public function create(
        Request $request,
        string $routeName,
        ?User $user,
        StatisticsFilter $filter,
        string $headingScope,
        string $headingPeriod,
        AnalysisContextPeriodMode $periodMode = AnalysisContextPeriodMode::Full,
        ?OverviewPeriodViewModel $monthPeriodViewModel = null,
    ): AnalysisContextViewModel {
        $locale = $request->getLocale();
        $now = new \DateTimeImmutable();
        $currentYear = (int) $now->format('Y');
        $currentMonth = (int) $now->format('n');
        $currentQuarter = (int) ceil($currentMonth / 3);

        $scopeGroup = $this->scopeGroup($filter);
        $scopeDetail = $this->scopeDetail($filter);
        $defaultScopeGroup = $this->defaultScopeGroup($user);
        [$yearChoices, $monthChoices] = $this->periodValueChoices(
            $periodMode,
            $monthPeriodViewModel,
            $locale,
            $currentYear,
        );
        [$appliedYear, $appliedMonth] = $this->appliedCalendarValues(
            $request,
            $filter,
            $periodMode,
            $monthPeriodViewModel,
            $currentYear,
            $currentMonth,
        );

        $stateChoices = $this->choiceProvider->scopeDetailChoices(
            'state',
            $user,
            StatisticsFilterSide::Primary,
            $locale,
        );
        $dispatchAreaChoices = $this->choiceProvider->scopeDetailChoices(
            'dispatch_area',
            $user,
            StatisticsFilterSide::Primary,
            $locale,
        );
        $cohortChoices = $this->choiceProvider->scopeDetailChoices(
            'hospital_cohort',
            $user,
            StatisticsFilterSide::Primary,
            $locale,
        );
        $hospitalChoices = $this->choiceProvider->scopeDetailChoices(
            'my_hospitals',
            $user,
            StatisticsFilterSide::Primary,
            $locale,
        );
        $formQueryKeys = $this->formQueryKeys($periodMode);
        $stateChoices = $this->stringChoices($stateChoices);
        $dispatchAreaChoices = $this->stringChoices($dispatchAreaChoices);
        $cohortChoices = $this->stringChoices($cohortChoices);
        $hospitalChoices = $this->stringChoices($hospitalChoices);
        $locationLabel = $this->locationLabel(
            $scopeGroup,
            $scopeDetail,
            $headingScope,
            $stateChoices,
            $dispatchAreaChoices,
            $cohortChoices,
            $hospitalChoices,
        );

        return new AnalysisContextViewModel(
            $this->summary($headingScope, $headingPeriod, $periodMode),
            $locationLabel,
            $headingScope,
            $headingPeriod,
            $periodMode,
            $this->formAction($request, $routeName),
            $filter->scope->value,
            $scopeGroup,
            $scopeDetail,
            $filter->period->value,
            $appliedYear,
            $filter->referenceQuarter ?? $currentQuarter,
            $appliedMonth,
            $defaultScopeGroup,
            StatisticsFilterPeriod::All->value,
            $currentYear,
            $currentQuarter,
            $currentMonth,
            $this->choiceProvider->scopePrimaryChoices($user, $locale),
            $stateChoices,
            $dispatchAreaChoices,
            $cohortChoices,
            $hospitalChoices,
            $this->choiceProvider->periodPrimaryChoices($locale),
            $this->withNumericChoice($yearChoices, $appliedYear),
            $this->stringChoices($this->quarterChoices($locale)),
            $this->withNumericChoice($monthChoices, $appliedMonth),
            $this->preservedQuery($request, $formQueryKeys),
            $formQueryKeys,
            AnalysisContextPeriodMode::Hidden === $periodMode,
            AnalysisContextPeriodMode::MonthOnly === $periodMode,
        );
    }

    private function scopeGroup(StatisticsFilter $filter): string
    {
        return match ($filter->scope) {
            StatisticsFilterScope::Public => 'public',
            StatisticsFilterScope::State => 'state',
            StatisticsFilterScope::DispatchArea => 'dispatch_area',
            StatisticsFilterScope::HospitalCohort => 'hospital_cohort',
            StatisticsFilterScope::MyHospitals,
            StatisticsFilterScope::Hospital => 'my_hospitals',
        };
    }

    private function scopeDetail(StatisticsFilter $filter): ?string
    {
        return match ($filter->scope) {
            StatisticsFilterScope::State => null !== $filter->stateId ? (string) $filter->stateId : null,
            StatisticsFilterScope::DispatchArea => null !== $filter->dispatchAreaId ? (string) $filter->dispatchAreaId : null,
            StatisticsFilterScope::HospitalCohort => $filter->cohortType?->value(),
            StatisticsFilterScope::Hospital => null !== $filter->hospitalId ? (string) $filter->hospitalId : null,
            default => null,
        };
    }

    private function defaultScopeGroup(?User $user): string
    {
        if ($user instanceof User
            && !$this->hospitalAccess->isAdminHospitalScopeUser($user)
            && $this->hospitalAccess->canUseMyHospitalsScope($user)
        ) {
            return 'my_hospitals';
        }

        return 'public';
    }

    private function summary(string $headingScope, string $headingPeriod, AnalysisContextPeriodMode $periodMode): string
    {
        if (AnalysisContextPeriodMode::Hidden === $periodMode || '' === $headingPeriod) {
            return $headingScope;
        }

        return $headingScope.' · '.$headingPeriod;
    }

    /**
     * @param array<string, string> $stateChoices
     * @param array<string, string> $dispatchAreaChoices
     * @param array<string, string> $cohortChoices
     * @param array<string, string> $hospitalChoices
     */
    private function locationLabel(
        string $scopeGroup,
        ?string $scopeDetail,
        string $headingScope,
        array $stateChoices,
        array $dispatchAreaChoices,
        array $cohortChoices,
        array $hospitalChoices,
    ): string {
        $detail = $scopeDetail ?? '';

        return match ($scopeGroup) {
            'state' => $this->choiceLabel($stateChoices, $detail, $headingScope),
            'dispatch_area' => $this->choiceLabel($dispatchAreaChoices, $detail, $headingScope),
            'hospital_cohort' => $this->choiceLabel($cohortChoices, $detail, $headingScope),
            'my_hospitals' => $this->choiceLabel($hospitalChoices, $detail, $headingScope),
            default => $headingScope,
        };
    }

    /**
     * @param array<string, string> $choices
     */
    private function choiceLabel(array $choices, string $detail, string $fallback): string
    {
        if (isset($choices[$detail]) && '' !== $choices[$detail]) {
            return $choices[$detail];
        }

        return $fallback;
    }

    private function formAction(Request $request, string $routeName): string
    {
        $routeParams = $request->attributes->get('_route_params', []);
        if (!\is_array($routeParams)) {
            $routeParams = [];
        }

        return $this->urlGenerator->generate($routeName, $routeParams);
    }

    /**
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function periodValueChoices(
        AnalysisContextPeriodMode $periodMode,
        ?OverviewPeriodViewModel $monthPeriodViewModel,
        string $locale,
        int $currentYear,
    ): array {
        if (AnalysisContextPeriodMode::MonthOnly === $periodMode && $monthPeriodViewModel instanceof OverviewPeriodViewModel) {
            return $this->choicesFromMonthPeriodMenus($monthPeriodViewModel);
        }

        return [
            $this->choiceProvider->periodYearChoices(),
            $this->stringChoices($this->monthNameChoices($currentYear, $locale)),
        ];
    }

    /**
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function choicesFromMonthPeriodMenus(OverviewPeriodViewModel $viewModel): array
    {
        $years = [];
        foreach ($viewModel->primaryMenu as $item) {
            $years[$item['key']] = $item['label'];
        }

        $months = [];
        foreach ($viewModel->secondaryMenu as $item) {
            if ($item['divider'] ?? false) {
                continue;
            }

            $month = $this->queryValue($item['url'], StatisticsQueryKeys::MONTH);
            if ('' === $month) {
                continue;
            }
            $months[$month] = $item['label'];
        }

        return [$years, $months];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function appliedCalendarValues(
        Request $request,
        StatisticsFilter $filter,
        AnalysisContextPeriodMode $periodMode,
        ?OverviewPeriodViewModel $monthPeriodViewModel,
        int $currentYear,
        int $currentMonth,
    ): array {
        if (AnalysisContextPeriodMode::MonthOnly === $periodMode && $monthPeriodViewModel instanceof OverviewPeriodViewModel) {
            $year = $this->queryInt($request, StatisticsQueryKeys::YEAR) ?? $currentYear;
            $month = $this->queryInt($request, StatisticsQueryKeys::MONTH) ?? $currentMonth;
            foreach ($monthPeriodViewModel->primaryMenu as $item) {
                if ($item['active']) {
                    $year = (int) $item['key'];
                    break;
                }
            }
            foreach ($monthPeriodViewModel->secondaryMenu as $item) {
                if ($item['divider'] ?? false) {
                    continue;
                }
                if (!($item['active'] ?? false)) {
                    continue;
                }
                $monthValue = $this->queryValue($item['url'], StatisticsQueryKeys::MONTH);
                if ('' !== $monthValue) {
                    $month = (int) $monthValue;
                }
                break;
            }

            return [$year, $month];
        }

        return [
            $filter->referenceYear ?? $this->queryInt($request, StatisticsQueryKeys::YEAR) ?? $currentYear,
            $filter->referenceMonth ?? $this->queryInt($request, StatisticsQueryKeys::MONTH) ?? $currentMonth,
        ];
    }

    private function queryInt(Request $request, string $key): ?int
    {
        $value = $request->query->get($key);
        if (!\is_string($value) || !\is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return array<int, string>
     */
    private function monthNameChoices(int $year, string $locale): array
    {
        $choices = [];
        for ($month = 1; $month <= 12; ++$month) {
            $midMonth = new \DateTimeImmutable(sprintf('%04d-%02d-15 12:00:00', $year, $month));
            $formatted = \IntlDateFormatter::formatObject($midMonth, 'LLLL', $locale);
            $choices[$month] = false !== $formatted && '' !== $formatted
                ? $formatted
                : sprintf('%02d', $month);
        }

        return $choices;
    }

    /**
     * @return array<int, string>
     */
    private function quarterChoices(string $locale): array
    {
        $choices = [];
        for ($quarter = 1; $quarter <= 4; ++$quarter) {
            $choices[$quarter] = $this->translator->trans(
                'stats.analysis_context.quarter_option',
                ['quarter' => (string) $quarter],
                'statistics',
                $locale,
            );
        }

        return $choices;
    }

    /**
     * @param array<int|string, string> $choices
     *
     * @return array<string, string>
     */
    private function withNumericChoice(array $choices, ?int $appliedValue): array
    {
        $normalized = $this->stringChoices($choices);
        if (null === $appliedValue) {
            return $normalized;
        }

        $key = (string) $appliedValue;
        if (!isset($normalized[$key])) {
            $normalized[$key] = $key;
            krsort($normalized, SORT_NUMERIC);

            return $this->stringChoices($normalized);
        }

        return $normalized;
    }

    /**
     * @param array<int|string, string> $choices
     *
     * @return array<string, string>
     */
    private function stringChoices(array $choices): array
    {
        $normalized = [];
        foreach ($choices as $value => $label) {
            $normalized[(string) $value] = $label;
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function formQueryKeys(AnalysisContextPeriodMode $periodMode): array
    {
        $scopeKeys = [
            StatisticsQueryKeys::SCOPE,
            StatisticsQueryKeys::HOSPITAL,
            StatisticsQueryKeys::COHORT,
            StatisticsQueryKeys::STATE,
            StatisticsQueryKeys::DISPATCH_AREA,
        ];

        return match ($periodMode) {
            AnalysisContextPeriodMode::Hidden => $scopeKeys,
            AnalysisContextPeriodMode::MonthOnly => [
                ...$scopeKeys,
                StatisticsQueryKeys::YEAR,
                StatisticsQueryKeys::MONTH,
            ],
            AnalysisContextPeriodMode::Full => [
                ...$scopeKeys,
                StatisticsQueryKeys::PERIOD,
                StatisticsQueryKeys::YEAR,
                StatisticsQueryKeys::MONTH,
                StatisticsQueryKeys::QUARTER,
            ],
        };
    }

    /**
     * @param list<string> $formQueryKeys
     *
     * @return array<string, string>
     */
    private function preservedQuery(Request $request, array $formQueryKeys): array
    {
        $skip = [
            ...$formQueryKeys,
            StatisticsQueryKeys::PAGE,
        ];
        $preserved = [];
        foreach ($request->query->all() as $key => $value) {
            if (\in_array($key, $skip, true) || \is_array($value)) {
                continue;
            }
            if (!\is_scalar($value)) {
                continue;
            }
            $preserved[$key] = (string) $value;
        }

        return $preserved;
    }

    private function queryValue(string $url, string $key): string
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (!\is_string($query) || '' === $query) {
            return '';
        }

        parse_str($query, $params);
        $value = $params[$key] ?? '';

        return \is_string($value) ? $value : '';
    }
}
