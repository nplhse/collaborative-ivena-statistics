<?php

declare(strict_types=1);

namespace App\Statistics\UI\Application;

use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Infrastructure\Repository\DispatchAreaRepository;
use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Allocation\Infrastructure\Repository\StateRepository;
use App\Statistics\Application\Cohort\HospitalCohortEligibilityChecker;
use App\Statistics\Application\Cohort\HospitalCohortKey;
use App\Statistics\Application\Cohort\HospitalCohortLabelResolver;
use App\Statistics\Application\Cohort\HospitalCohortResolver;
use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\StatisticsHospitalScopeLabelResolver;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\User\Domain\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class StatisticsScopeViewModelBuilder
{
    public function __construct(
        private HospitalRepository $hospitalRepository,
        private HospitalAccessInterface $hospitalAccess,
        private HospitalCohortResolver $hospitalCohortResolver,
        private HospitalCohortEligibilityChecker $hospitalCohortEligibilityChecker,
        private HospitalCohortLabelResolver $hospitalCohortLabelResolver,
        private TranslatorInterface $translator,
        private StatisticsHospitalScopeLabelResolver $hospitalScopeLabelResolver,
        private StatisticsNavigationUrlBuilder $statisticsNavigationUrlBuilder,
        private StatisticsFilterFormChoiceProvider $filterFormChoiceProvider,
        private StateRepository $stateRepository,
        private DispatchAreaRepository $dispatchAreaRepository,
    ) {
    }

    public function build(
        Request $request,
        string $routeName,
        ?User $user,
        StatisticsFilter $filter,
        StatisticsFilterNavigationContext $navigation,
    ): StatisticsScopeViewModelData {
        $now = new \DateTimeImmutable();
        $defaultYear = $filter->referenceYear ?? (int) $now->format('Y');
        $defaultMonth = $filter->referenceMonth ?? (int) $now->format('n');
        $locale = $request->getLocale();

        $scopeUrls = [
            'public' => $this->statisticsNavigationUrlBuilder->build(
                $request,
                $routeName,
                [$navigation->scopeQueryKey => StatisticsFilterScope::Public->value],
                $navigation->removeScopeDependent,
            ),
            'my_hospitals' => $this->statisticsNavigationUrlBuilder->build(
                $request,
                $routeName,
                [$navigation->scopeQueryKey => StatisticsFilterScope::MyHospitals->value],
                $navigation->removeScopeDependent,
            ),
        ];

        $accessibleHospitals = [];
        $hospitalUrls = [];
        if ($user instanceof User && $this->canUseGroupedHospitalScope($user, $navigation)) {
            $accessibleHospitals = $this->findAccessibleHospitals($user, $navigation);
            foreach ($accessibleHospitals as $row) {
                $hospitalUrls[$row['id']] = $this->statisticsNavigationUrlBuilder->build(
                    $request,
                    $routeName,
                    [
                        $navigation->scopeQueryKey => StatisticsFilterScope::Hospital->value,
                        $navigation->hospitalQueryKey => $row['id'],
                    ],
                );
            }
        }

        $periodUrls = [
            'all' => $this->statisticsNavigationUrlBuilder->build(
                $request,
                $routeName,
                [$navigation->periodQueryKey => StatisticsFilterPeriod::All->value],
                $navigation->removePeriodDependent,
            ),
            'all_time' => $this->statisticsNavigationUrlBuilder->build(
                $request,
                $routeName,
                [$navigation->periodQueryKey => StatisticsFilterPeriod::AllTime->value],
                $navigation->removePeriodDependent,
            ),
            'year' => $this->statisticsNavigationUrlBuilder->build(
                $request,
                $routeName,
                [
                    $navigation->periodQueryKey => StatisticsFilterPeriod::Year->value,
                    $navigation->yearQueryKey => $defaultYear,
                ],
                $navigation->removeMonthDependent,
            ),
            'month' => $this->statisticsNavigationUrlBuilder->build(
                $request,
                $routeName,
                [
                    $navigation->periodQueryKey => StatisticsFilterPeriod::Month->value,
                    $navigation->yearQueryKey => $defaultYear,
                    $navigation->monthQueryKey => $defaultMonth,
                ],
            ),
            'quarter' => $this->statisticsNavigationUrlBuilder->build(
                $request,
                $routeName,
                [
                    $navigation->periodQueryKey => StatisticsFilterPeriod::Quarter->value,
                    $navigation->yearQueryKey => $defaultYear,
                    $navigation->quarterQueryKey => (int) ceil($defaultMonth / 3),
                ],
                $navigation->removeMonthDependent,
            ),
        ];

        $cohortScopeChoices = [];
        foreach (HospitalCohortKey::all() as $cohortKey) {
            $cohort = $this->hospitalCohortResolver->resolve($cohortKey);
            if (!$this->hospitalCohortEligibilityChecker->hasMinimumParticipants($cohort)) {
                continue;
            }
            $cohortScopeChoices[] = [
                'key' => $cohortKey->value(),
                'label' => $this->hospitalCohortLabelResolver->label($cohortKey, $locale),
                'url' => $this->statisticsNavigationUrlBuilder->build(
                    $request,
                    $routeName,
                    [
                        $navigation->scopeQueryKey => StatisticsFilterScope::HospitalCohort->value.':'.$cohortKey->value(),
                    ],
                    $navigation->removeScopeDependent,
                ),
                'active' => StatisticsFilterScope::HospitalCohort === $filter->scope
                    && $filter->cohortType instanceof HospitalCohortKey
                    && $filter->cohortType->equals($cohortKey),
            ];
        }
        $cohortDropdownSelectedName = null;
        foreach ($cohortScopeChoices as $cohortChoice) {
            if ($cohortChoice['active']) {
                $cohortDropdownSelectedName = $cohortChoice['label'];
                break;
            }
        }

        $eligibleStateRows = $this->filterFormChoiceProvider->eligibleStateRows();
        $eligibleDispatchRows = $this->filterFormChoiceProvider->eligibleDispatchAreaRows();

        $scopePrimaryMenu = $this->buildScopePrimaryMenu(
            $request,
            $routeName,
            $filter,
            $navigation,
            $scopeUrls,
            $eligibleStateRows,
            $eligibleDispatchRows,
            $cohortScopeChoices,
            $user,
            $locale,
        );

        $scopeSecondaryMenu = $this->buildScopeSecondaryMenu(
            $request,
            $routeName,
            $filter,
            $navigation,
            $scopeUrls,
            $hospitalUrls,
            $eligibleStateRows,
            $eligibleDispatchRows,
            $cohortScopeChoices,
            $user,
            $accessibleHospitals,
        );

        $myHospitalsDual = $user instanceof User
            && $this->canUseGroupedHospitalScope($user, $navigation)
            && \count($accessibleHospitals) > 1
            && (StatisticsFilterScope::MyHospitals === $filter->scope || StatisticsFilterScope::Hospital === $filter->scope);
        $stateDual = StatisticsFilterScope::State === $filter->scope && [] !== $eligibleStateRows;
        $dispatchDual = StatisticsFilterScope::DispatchArea === $filter->scope && [] !== $eligibleDispatchRows;
        $cohortDual = StatisticsFilterScope::HospitalCohort === $filter->scope && [] !== $cohortScopeChoices;
        $showScopeSecondaryPicker = $myHospitalsDual || $stateDual || $dispatchDual || $cohortDual;

        $hospitalDropdownSelectedName = null;
        if (StatisticsFilterScope::Hospital === $filter->scope && null !== $filter->hospitalId) {
            foreach ($accessibleHospitals as $row) {
                if ($row['id'] === $filter->hospitalId) {
                    $hospitalDropdownSelectedName = $row['name'];
                    break;
                }
            }
        }

        $showUnscopedHint = $user instanceof User
            && [] === $accessibleHospitals
            && StatisticsFilterScope::MyHospitals === $filter->scope;

        [$scopePrimaryDropdownLabel, $scopeSecondaryDropdownLabel] = $this->scopeDropdownLabels(
            $filter,
            $user,
            $accessibleHospitals,
            $locale,
            $showScopeSecondaryPicker,
            $showUnscopedHint,
            $hospitalDropdownSelectedName,
        );

        $stateDisplayName = null;
        if (StatisticsFilterScope::State === $filter->scope && null !== $filter->stateId) {
            $stateDisplayName = $this->stateRepository->findById($filter->stateId)?->getName();
        }
        $dispatchDisplayName = null;
        if (StatisticsFilterScope::DispatchArea === $filter->scope && null !== $filter->dispatchAreaId) {
            $dispatchDisplayName = $this->dispatchAreaRepository->findById($filter->dispatchAreaId)?->getName();
        }

        return new StatisticsScopeViewModelData(
            scopeUrls: $scopeUrls,
            hospitalUrls: $hospitalUrls,
            cohortScopeChoices: $cohortScopeChoices,
            cohortDropdownSelectedName: $cohortDropdownSelectedName,
            periodUrls: $periodUrls,
            accessibleHospitals: $accessibleHospitals,
            hospitalDropdownSelectedName: $hospitalDropdownSelectedName,
            headingScope: $this->statisticsHeadingScope(
                $filter,
                $user,
                $hospitalDropdownSelectedName,
                $locale,
                $showUnscopedHint,
                \count($accessibleHospitals),
                $stateDisplayName,
                $dispatchDisplayName,
            ),
            headingPeriod: $this->statisticsHeadingPeriod($filter, $locale),
            showUnscopedHint: $showUnscopedHint,
            scopePrimaryMenu: $scopePrimaryMenu,
            scopeSecondaryMenu: $scopeSecondaryMenu,
            showScopeSecondaryPicker: $showScopeSecondaryPicker,
            scopePrimaryDropdownLabel: $scopePrimaryDropdownLabel,
            scopeSecondaryDropdownLabel: $scopeSecondaryDropdownLabel,
        );
    }

    private function canUseGroupedHospitalScope(User $user, StatisticsFilterNavigationContext $navigation): bool
    {
        return match ($navigation->variant) {
            StatisticsScopeViewModelVariant::Statistics => $this->hospitalAccess->canUseMyHospitalsScope($user),
            StatisticsScopeViewModelVariant::Benchmarking => $this->hospitalAccess->canUseBenchmarkingScope($user),
        };
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function findAccessibleHospitals(User $user, StatisticsFilterNavigationContext $navigation): array
    {
        return match ($navigation->variant) {
            StatisticsScopeViewModelVariant::Statistics => $this->hospitalRepository->findAccessibleParticipatingHospitalSummaries($user),
            StatisticsScopeViewModelVariant::Benchmarking => $this->hospitalRepository->findAccessibleParticipatingHospitalSummaries($user, HospitalPermission::Benchmarking),
        };
    }

    public function headingPeriod(StatisticsFilter $filter, string $locale): string
    {
        return $this->statisticsHeadingPeriod($filter, $locale);
    }

    /**
     * @param array<string, string>                                              $scopeUrls
     * @param list<array{id: int, name: string}>                                 $eligibleStateRows
     * @param list<array{id: int, name: string}>                                 $eligibleDispatchRows
     * @param list<array{key: string, label: string, url: string, active: bool}> $cohortScopeChoices
     *
     * @return list<array{key: string, label: string, url: string, active: bool}>
     */
    private function buildScopePrimaryMenu(
        Request $request,
        string $routeName,
        StatisticsFilter $filter,
        StatisticsFilterNavigationContext $navigation,
        array $scopeUrls,
        array $eligibleStateRows,
        array $eligibleDispatchRows,
        array $cohortScopeChoices,
        ?User $user,
        string $locale,
    ): array {
        $menu = [];
        $menu[] = [
            'key' => 'public',
            'label' => $this->translator->trans('stats.filter.scope.public', [], 'statistics', $locale),
            'url' => $scopeUrls['public'],
            'active' => StatisticsFilterScope::Public === $filter->scope,
        ];

        if ([] !== $eligibleStateRows) {
            $first = $eligibleStateRows[0];
            $menu[] = [
                'key' => 'state_group',
                'label' => $this->translator->trans('stats.filter.scope.state', [], 'statistics', $locale),
                'url' => $this->buildStateScopeUrl($request, $routeName, $navigation, $first['id']),
                'active' => StatisticsFilterScope::State === $filter->scope,
            ];
        }

        if ([] !== $eligibleDispatchRows) {
            $first = $eligibleDispatchRows[0];
            $menu[] = [
                'key' => 'dispatch_area_group',
                'label' => $this->translator->trans('stats.filter.scope.dispatch_area', [], 'statistics', $locale),
                'url' => $this->buildDispatchAreaScopeUrl($request, $routeName, $navigation, $first['id']),
                'active' => StatisticsFilterScope::DispatchArea === $filter->scope,
            ];
        }

        if ([] !== $cohortScopeChoices) {
            $first = $cohortScopeChoices[0];
            $menu[] = [
                'key' => 'cohort_group',
                'label' => $this->translator->trans('stats.filter.scope.hospital_cohort', [], 'statistics', $locale),
                'url' => $first['url'],
                'active' => StatisticsFilterScope::HospitalCohort === $filter->scope,
            ];
        }

        if ($user instanceof User && $this->canUseGroupedHospitalScope($user, $navigation)) {
            $menu[] = [
                'key' => 'my_hospitals_group',
                'label' => $this->hospitalScopeLabelResolver->groupLabel($user, $locale),
                'url' => $scopeUrls['my_hospitals'],
                'active' => StatisticsFilterScope::MyHospitals === $filter->scope
                    || StatisticsFilterScope::Hospital === $filter->scope,
            ];
        }

        return $menu;
    }

    /**
     * @param array<string, string>                                              $scopeUrls
     * @param list<array{id: int, name: string}>                                 $eligibleStateRows
     * @param list<array{id: int, name: string}>                                 $eligibleDispatchRows
     * @param list<array{key: string, label: string, url: string, active: bool}> $cohortScopeChoices
     * @param array<int, string>                                                 $hospitalUrls
     * @param list<array{id: int, name: string}>                                 $accessibleHospitals
     *
     * @return list<array{label: string, url: string, active: bool}>
     */
    private function buildScopeSecondaryMenu(
        Request $request,
        string $routeName,
        StatisticsFilter $filter,
        StatisticsFilterNavigationContext $navigation,
        array $scopeUrls,
        array $hospitalUrls,
        array $eligibleStateRows,
        array $eligibleDispatchRows,
        array $cohortScopeChoices,
        ?User $user,
        array $accessibleHospitals,
    ): array {
        if (StatisticsFilterScope::State === $filter->scope && [] !== $eligibleStateRows) {
            $menu = [];
            foreach ($eligibleStateRows as $row) {
                $menu[] = [
                    'label' => $row['name'],
                    'url' => $this->buildStateScopeUrl($request, $routeName, $navigation, $row['id']),
                    'active' => null !== $filter->stateId && $filter->stateId === $row['id'],
                ];
            }

            return $menu;
        }

        if (StatisticsFilterScope::DispatchArea === $filter->scope && [] !== $eligibleDispatchRows) {
            $menu = [];
            foreach ($eligibleDispatchRows as $row) {
                $menu[] = [
                    'label' => $row['name'],
                    'url' => $this->buildDispatchAreaScopeUrl($request, $routeName, $navigation, $row['id']),
                    'active' => null !== $filter->dispatchAreaId && $filter->dispatchAreaId === $row['id'],
                ];
            }

            return $menu;
        }

        if (StatisticsFilterScope::HospitalCohort === $filter->scope && [] !== $cohortScopeChoices) {
            $menu = [];
            foreach ($cohortScopeChoices as $c) {
                $menu[] = [
                    'label' => $c['label'],
                    'url' => $c['url'],
                    'active' => true === $c['active'],
                ];
            }

            return $menu;
        }

        if ($user instanceof User
            && $this->canUseGroupedHospitalScope($user, $navigation)
            && \count($accessibleHospitals) > 1
            && (StatisticsFilterScope::MyHospitals === $filter->scope || StatisticsFilterScope::Hospital === $filter->scope)
        ) {
            $menu = [[
                'label' => $this->translator->trans('stats.filter.hospital.all_hospitals', [], 'statistics'),
                'url' => $scopeUrls['my_hospitals'],
                'active' => StatisticsFilterScope::MyHospitals === $filter->scope,
            ]];
            foreach ($accessibleHospitals as $row) {
                $menu[] = [
                    'label' => $row['name'],
                    'url' => $hospitalUrls[$row['id']] ?? $scopeUrls['my_hospitals'],
                    'active' => StatisticsFilterScope::Hospital === $filter->scope && $filter->hospitalId === $row['id'],
                ];
            }

            return $menu;
        }

        return [];
    }

    /**
     * @param list<array{id: int, name: string}> $accessibleHospitals
     *
     * @return array{0: string, 1: ?string}
     */
    private function scopeDropdownLabels(
        StatisticsFilter $filter,
        ?User $user,
        array $accessibleHospitals,
        string $locale,
        bool $showScopeSecondaryPicker,
        bool $showUnscopedHint,
        ?string $hospitalDropdownSelectedName,
    ): array {
        if (!$showScopeSecondaryPicker) {
            return [
                $this->statisticsHeadingScope(
                    $filter,
                    $user,
                    $hospitalDropdownSelectedName,
                    $locale,
                    $showUnscopedHint,
                    \count($accessibleHospitals),
                    $this->stateNameForFilter($filter),
                    $this->dispatchNameForFilter($filter),
                ),
                null,
            ];
        }

        $hospitalGroupLabel = $this->hospitalScopeLabelResolver->groupLabel($user, $locale);

        $primary = match ($filter->scope) {
            StatisticsFilterScope::Public => $this->translator->trans('stats.filter.scope.public', [], 'statistics', $locale),
            StatisticsFilterScope::State => $this->translator->trans('stats.filter.scope.state', [], 'statistics', $locale),
            StatisticsFilterScope::DispatchArea => $this->translator->trans('stats.filter.scope.dispatch_area', [], 'statistics', $locale),
            StatisticsFilterScope::HospitalCohort => $this->translator->trans('stats.filter.scope.hospital_cohort', [], 'statistics', $locale),
            StatisticsFilterScope::MyHospitals,
            StatisticsFilterScope::Hospital => $hospitalGroupLabel,
        };

        $secondary = match ($filter->scope) {
            StatisticsFilterScope::State => $this->stateNameForFilter($filter)
                ?? $this->translator->trans('stats.filter.scope.choose_region', [], 'statistics', $locale),
            StatisticsFilterScope::DispatchArea => $this->dispatchNameForFilter($filter)
                ?? $this->translator->trans('stats.filter.scope.choose_dispatch_area', [], 'statistics', $locale),
            StatisticsFilterScope::HospitalCohort => $filter->cohortType instanceof HospitalCohortKey
                ? $this->hospitalCohortLabelResolver->label($filter->cohortType, $locale)
                : $this->translator->trans('stats.filter.scope.choose_cohort', [], 'statistics', $locale),
            StatisticsFilterScope::MyHospitals => $this->translator->trans('stats.filter.hospital.all_hospitals', [], 'statistics', $locale),
            StatisticsFilterScope::Hospital => $hospitalDropdownSelectedName
                ?? $this->translator->trans('stats.filter.hospital.choose', [], 'statistics', $locale),
            default => null,
        };

        return [$primary, $secondary];
    }

    private function stateNameForFilter(StatisticsFilter $filter): ?string
    {
        if (StatisticsFilterScope::State !== $filter->scope || null === $filter->stateId) {
            return null;
        }

        return $this->stateRepository->findById($filter->stateId)?->getName();
    }

    private function dispatchNameForFilter(StatisticsFilter $filter): ?string
    {
        if (StatisticsFilterScope::DispatchArea !== $filter->scope || null === $filter->dispatchAreaId) {
            return null;
        }

        return $this->dispatchAreaRepository->findById($filter->dispatchAreaId)?->getName();
    }

    private function buildStateScopeUrl(
        Request $request,
        string $routeName,
        StatisticsFilterNavigationContext $navigation,
        int $stateId,
    ): string {
        return $this->statisticsNavigationUrlBuilder->build(
            $request,
            $routeName,
            [
                $navigation->scopeQueryKey => StatisticsFilterScope::State->value.':'.$stateId,
            ],
            $navigation->removeScopeDependent,
        );
    }

    private function buildDispatchAreaScopeUrl(
        Request $request,
        string $routeName,
        StatisticsFilterNavigationContext $navigation,
        int $dispatchAreaId,
    ): string {
        return $this->statisticsNavigationUrlBuilder->build(
            $request,
            $routeName,
            [
                $navigation->scopeQueryKey => StatisticsFilterScope::DispatchArea->value.':'.$dispatchAreaId,
            ],
            $navigation->removeScopeDependent,
        );
    }

    private function statisticsHeadingScope(
        StatisticsFilter $filter,
        ?User $user,
        ?string $hospitalDisplayName,
        string $locale,
        bool $loggedInUserHasNoAccessibleHospitals,
        int $accessibleHospitalCount,
        ?string $stateDisplayName,
        ?string $dispatchDisplayName,
    ): string {
        $hospitalGroupLabel = $this->hospitalScopeLabelResolver->groupLabel($user, $locale);

        if (StatisticsFilterScope::MyHospitals === $filter->scope && $loggedInUserHasNoAccessibleHospitals) {
            return $this->translator->trans('stats.filter.scope.public', [], 'statistics', $locale);
        }

        if (1 === $accessibleHospitalCount && StatisticsFilterScope::Hospital === $filter->scope) {
            return $hospitalGroupLabel;
        }

        return match ($filter->scope) {
            StatisticsFilterScope::Public => $this->translator->trans('stats.filter.scope.public', [], 'statistics', $locale),
            StatisticsFilterScope::MyHospitals => $hospitalGroupLabel,
            StatisticsFilterScope::Hospital => (null !== $hospitalDisplayName && '' !== $hospitalDisplayName)
                ? $this->translator->trans('stats.filter.hospital.named_line', ['name' => $hospitalDisplayName], 'statistics', $locale)
                : $this->translator->trans('stats.filter.hospital.choose', [], 'statistics', $locale),
            StatisticsFilterScope::HospitalCohort => $filter->cohortType instanceof HospitalCohortKey
                ? $this->hospitalCohortLabelResolver->label($filter->cohortType, $locale)
                : $this->translator->trans('stats.filter.scope.hospital_cohort', [], 'statistics', $locale),
            StatisticsFilterScope::State => (null !== $stateDisplayName && '' !== $stateDisplayName)
                ? $stateDisplayName
                : $this->translator->trans('stats.filter.scope.state', [], 'statistics', $locale),
            StatisticsFilterScope::DispatchArea => (null !== $dispatchDisplayName && '' !== $dispatchDisplayName)
                ? $dispatchDisplayName
                : $this->translator->trans('stats.filter.scope.dispatch_area', [], 'statistics', $locale),
        };
    }

    private function statisticsHeadingPeriod(StatisticsFilter $filter, string $locale): string
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
            StatisticsFilterPeriod::Month => $this->statisticsHeadingMonthPeriod($filter, $locale),
        };
    }

    private function statisticsHeadingMonthPeriod(StatisticsFilter $filter, string $locale): string
    {
        $now = new \DateTimeImmutable();
        $year = $filter->referenceYear ?? (int) $now->format('Y');
        $month = $filter->referenceMonth ?? (int) $now->format('n');
        $month = max(1, min(12, $month));
        $midMonth = new \DateTimeImmutable(sprintf('%04d-%02d-15 12:00:00', $year, $month));

        $pattern = 'LLLL yyyy';
        $formatted = \IntlDateFormatter::formatObject($midMonth, $pattern, $locale);
        if (false !== $formatted && '' !== $formatted) {
            return $formatted;
        }

        return sprintf('%04d-%02d', $year, $month);
    }
}
