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
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\StatisticsHospitalScopeLabelResolver;
use App\Statistics\Application\StatisticsPeriodNavigation;
use App\Statistics\Benchmarking\UI\Form\Data\BenchmarkSelectionSideFormData;
use App\Statistics\Infrastructure\Query\AllocationStatsProjectionScopeQuery;
use App\Statistics\Infrastructure\Query\Overview\GetEligibleDispatchAreaIdsQuery;
use App\Statistics\Infrastructure\Query\Overview\GetEligibleStateIdsQuery;
use App\Statistics\UI\Form\Data\StatisticsScopePeriodFormData;
use App\User\Domain\Entity\User;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Scope/period choice lists for statistics filter forms.
 *
 * Entity-backed lists are memoized for the lifetime of this service (request scope)
 * so LiveComponent form rebuilds do not repeat the same lookups.
 */
final class StatisticsFilterFormChoiceProvider
{
    /** @var array<string, list<array{id: int, name: string}>> */
    private array $eligibleStateRowsByPolicy = [];

    /** @var array<string, list<array{id: int, name: string}>> */
    private array $eligibleDispatchAreaRowsByPolicy = [];

    /** @var array<string, list<array{key: string, label: string}>> */
    private array $eligibleCohortChoicesByLocale = [];

    /** @var array<string, array<int|string, string>> */
    private array $hospitalDetailChoicesByKey = [];

    /** @var array<string, array<string, string>> */
    private array $scopePrimaryChoicesByKey = [];

    public function __construct(
        private readonly HospitalRepository $hospitalRepository,
        private readonly HospitalAccessInterface $hospitalAccess,
        private readonly HospitalCohortResolver $hospitalCohortResolver,
        private readonly HospitalCohortEligibilityChecker $hospitalCohortEligibilityChecker,
        private readonly HospitalCohortLabelResolver $hospitalCohortLabelResolver,
        private readonly StatisticsHospitalScopeLabelResolver $hospitalScopeLabelResolver,
        private readonly StatisticsPeriodNavigation $periodNavigation,
        private readonly GetEligibleStateIdsQuery $eligibleStateIdsQuery,
        private readonly GetEligibleDispatchAreaIdsQuery $eligibleDispatchAreaIdsQuery,
        private readonly AllocationStatsProjectionScopeQuery $projectionScopeQuery,
        private readonly StateRepository $stateRepository,
        private readonly DispatchAreaRepository $dispatchAreaRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function scopePrimaryChoices(
        ?User $user,
        string $locale,
        StatisticsFilterScopeChoicePolicy $policy = StatisticsFilterScopeChoicePolicy::RegisteredHospitals,
    ): array {
        $cacheKey = sprintf('%s|%s|%s', $user?->getId() ?? 'anon', $locale, $policy->value);
        if (isset($this->scopePrimaryChoicesByKey[$cacheKey])) {
            return $this->scopePrimaryChoicesByKey[$cacheKey];
        }

        $choices = [
            'public' => $this->translator->trans('stats.filter.scope.public', [], 'statistics', $locale),
        ];

        if ([] !== $this->eligibleStateRows($policy)) {
            $choices['state'] = $this->translator->trans('stats.filter.scope.state', [], 'statistics', $locale);
        }

        if ([] !== $this->eligibleDispatchAreaRows($policy)) {
            $choices['dispatch_area'] = $this->translator->trans('stats.filter.scope.dispatch_area', [], 'statistics', $locale);
        }

        if ([] !== $this->eligibleCohortChoices($locale)) {
            $choices['hospital_cohort'] = $this->translator->trans('stats.filter.scope.hospital_cohort', [], 'statistics', $locale);
        }

        if ($user instanceof User && $this->hospitalAccess->canUseMyHospitalsScope($user)) {
            $choices['my_hospitals'] = $this->hospitalScopeLabelResolver->groupLabel($user, $locale);
        }

        return $this->scopePrimaryChoicesByKey[$cacheKey] = $choices;
    }

    /**
     * @return array<int|string, string>
     */
    public function scopeDetailChoices(
        string $scopeGroup,
        ?User $user,
        StatisticsFilterSide $side,
        string $locale,
        StatisticsFilterScopeChoicePolicy $policy = StatisticsFilterScopeChoicePolicy::RegisteredHospitals,
    ): array {
        return match ($scopeGroup) {
            'state' => $this->stateDetailChoices($policy),
            'dispatch_area' => $this->dispatchAreaDetailChoices($policy),
            'hospital_cohort' => $this->cohortDetailChoices($locale),
            'my_hospitals' => $this->hospitalDetailChoices($user, $side, $locale),
            default => [],
        };
    }

    public function scopeDetailRequired(
        string $scopeGroup,
        ?User $user,
        StatisticsFilterSide $side,
        StatisticsFilterScopeChoicePolicy $policy = StatisticsFilterScopeChoicePolicy::RegisteredHospitals,
    ): bool {
        $choices = $this->scopeDetailChoices($scopeGroup, $user, $side, 'en', $policy);

        return [] !== $choices;
    }

    public function normalizeSideFormData(
        BenchmarkSelectionSideFormData $data,
        ?User $user,
        StatisticsFilterSide $side,
        string $locale,
        StatisticsFilterScopeChoicePolicy $policy = StatisticsFilterScopeChoicePolicy::RegisteredHospitals,
    ): BenchmarkSelectionSideFormData {
        [$scopeGroup, $scopeDetail] = $this->normalizeScopeDetail(
            $data->scopeGroup,
            $data->scopeDetail,
            $user,
            $side,
            $locale,
            $policy,
        );

        return new BenchmarkSelectionSideFormData(
            $scopeGroup,
            $scopeDetail,
            $data->period,
            $data->periodYear,
            $data->periodQuarter,
            $data->periodMonth,
        );
    }

    public function normalizeScopePeriodFormData(
        StatisticsScopePeriodFormData $data,
        ?User $user,
        StatisticsFilterSide $side,
        string $locale,
        StatisticsFilterScopeChoicePolicy $policy = StatisticsFilterScopeChoicePolicy::RegisteredHospitals,
    ): StatisticsScopePeriodFormData {
        [$scopeGroup, $scopeDetail] = $this->normalizeScopeDetail(
            $data->scopeGroup,
            $data->scopeDetail,
            $user,
            $side,
            $locale,
            $policy,
        );

        return new StatisticsScopePeriodFormData(
            $scopeGroup,
            $scopeDetail,
            $data->period,
            $data->periodYear,
            $data->periodQuarter,
            $data->periodMonth,
        );
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function normalizeScopeDetail(
        string $scopeGroup,
        ?string $scopeDetail,
        ?User $user,
        StatisticsFilterSide $side,
        string $locale,
        StatisticsFilterScopeChoicePolicy $policy,
    ): array {
        $primaryChoices = $this->scopePrimaryChoices($user, $locale, $policy);
        if (!isset($primaryChoices[$scopeGroup])) {
            return ['public', null];
        }

        if (!$this->scopeDetailRequired($scopeGroup, $user, $side, $policy)) {
            return [$scopeGroup, null];
        }

        $detailChoices = $this->scopeDetailChoices($scopeGroup, $user, $side, $locale, $policy);
        if (null === $scopeDetail || '' === $scopeDetail || !isset($detailChoices[$scopeDetail])) {
            $firstChoice = array_key_first($detailChoices);
            $scopeDetail = null !== $firstChoice ? (string) $firstChoice : null;
        }

        return [$scopeGroup, $scopeDetail];
    }

    /**
     * @return array<string, string>
     */
    public function periodPrimaryChoices(string $locale): array
    {
        return [
            StatisticsFilterPeriod::AllTime->value => $this->translator->trans('stats.filter.period.all_time', [], 'statistics', $locale),
            StatisticsFilterPeriod::All->value => $this->translator->trans('stats.filter.period.all', [], 'statistics', $locale),
            StatisticsFilterPeriod::Year->value => $this->translator->trans('stats.filter.period.year', [], 'statistics', $locale),
            StatisticsFilterPeriod::Quarter->value => $this->translator->trans('stats.filter.period.quarter', [], 'statistics', $locale),
            StatisticsFilterPeriod::Month->value => $this->translator->trans('stats.filter.period.month', [], 'statistics', $locale),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function periodYearChoices(): array
    {
        /** @var array<string, string> $choices */
        $choices = [];
        for ($year = $this->periodNavigation->currentYear(); $year >= $this->periodNavigation->earliestYear(); --$year) {
            $key = (string) $year;
            $choices[$key] = $key;
        }

        return $choices;
    }

    /**
     * @return array<string, string>
     */
    public function periodQuarterChoices(int $year, string $locale): array
    {
        return $this->labeledChoices(
            ['1', '2', '3', '4'],
            fn (string $key): string => $this->translator->trans('stats.dashboard.heading.quarter', [
                'quarter' => $key,
                'year' => (string) $year,
            ], 'statistics', $locale),
        );
    }

    /**
     * @return array<string, string>
     */
    public function periodMonthChoices(int $year, string $locale): array
    {
        return $this->labeledChoices(
            $this->numericStringKeys(1, 12),
            fn (string $key): string => $this->monthLabel($year, (int) $key, $locale),
        );
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function eligibleStateRows(
        StatisticsFilterScopeChoicePolicy $policy = StatisticsFilterScopeChoicePolicy::RegisteredHospitals,
    ): array {
        $cacheKey = $policy->value;
        if (isset($this->eligibleStateRowsByPolicy[$cacheKey])) {
            return $this->eligibleStateRowsByPolicy[$cacheKey];
        }

        $ids = array_values(array_filter(
            $this->eligibleStateIds($policy),
            static fn (int $id): bool => $id > 0,
        ));
        $namesById = $this->stateRepository->findNamesByIds($ids);
        $rows = [];
        foreach ($ids as $stateId) {
            $name = $namesById[$stateId] ?? null;
            if (null === $name || '' === $name) {
                continue;
            }
            $rows[] = ['id' => $stateId, 'name' => $name];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $this->eligibleStateRowsByPolicy[$cacheKey] = $rows;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function eligibleDispatchAreaRows(
        StatisticsFilterScopeChoicePolicy $policy = StatisticsFilterScopeChoicePolicy::RegisteredHospitals,
    ): array {
        $cacheKey = $policy->value;
        if (isset($this->eligibleDispatchAreaRowsByPolicy[$cacheKey])) {
            return $this->eligibleDispatchAreaRowsByPolicy[$cacheKey];
        }

        $ids = array_values(array_filter(
            $this->eligibleDispatchAreaIds($policy),
            static fn (int $id): bool => $id > 0,
        ));
        $namesById = $this->dispatchAreaRepository->findNamesByIds($ids);
        $rows = [];
        foreach ($ids as $dispatchAreaId) {
            $name = $namesById[$dispatchAreaId] ?? null;
            if (null === $name || '' === $name) {
                continue;
            }
            $rows[] = ['id' => $dispatchAreaId, 'name' => $name];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $this->eligibleDispatchAreaRowsByPolicy[$cacheKey] = $rows;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public function eligibleCohortChoices(string $locale): array
    {
        if (isset($this->eligibleCohortChoicesByLocale[$locale])) {
            return $this->eligibleCohortChoicesByLocale[$locale];
        }

        $choices = [];
        foreach (HospitalCohortKey::all() as $cohortKey) {
            $cohort = $this->hospitalCohortResolver->resolve($cohortKey);
            if (!$this->hospitalCohortEligibilityChecker->hasMinimumParticipants($cohort)) {
                continue;
            }
            $choices[] = [
                'key' => $cohortKey->value(),
                'label' => $this->hospitalCohortLabelResolver->label($cohortKey, $locale),
            ];
        }

        return $this->eligibleCohortChoicesByLocale[$locale] = $choices;
    }

    /**
     * @return array<int|string, string>
     */
    private function stateDetailChoices(
        StatisticsFilterScopeChoicePolicy $policy = StatisticsFilterScopeChoicePolicy::RegisteredHospitals,
    ): array {
        /** @var array<int|string, string> $choices */
        $choices = [];
        foreach ($this->eligibleStateRows($policy) as $row) {
            $choices[(string) $row['id']] = $row['name'];
        }

        return $choices;
    }

    /**
     * @return array<int|string, string>
     */
    private function dispatchAreaDetailChoices(
        StatisticsFilterScopeChoicePolicy $policy = StatisticsFilterScopeChoicePolicy::RegisteredHospitals,
    ): array {
        /** @var array<int|string, string> $choices */
        $choices = [];
        foreach ($this->eligibleDispatchAreaRows($policy) as $row) {
            $choices[(string) $row['id']] = $row['name'];
        }

        return $choices;
    }

    /**
     * @return array<int|string, string>
     */
    private function cohortDetailChoices(string $locale): array
    {
        $choices = [];
        foreach ($this->eligibleCohortChoices($locale) as $row) {
            $choices[$row['key']] = $row['label'];
        }

        return $choices;
    }

    /**
     * @return array<int|string, string>
     */
    private function hospitalDetailChoices(?User $user, StatisticsFilterSide $side, string $locale): array
    {
        if (!$user instanceof User) {
            return [];
        }

        $cacheKey = sprintf('%d|%s|%s', $user->getId() ?? 0, $side->value, $locale);
        if (isset($this->hospitalDetailChoicesByKey[$cacheKey])) {
            return $this->hospitalDetailChoicesByKey[$cacheKey];
        }

        $useBenchmarkingPermission = StatisticsFilterSide::Comparison === $side;
        if ($useBenchmarkingPermission && !$this->hospitalAccess->canUseBenchmarkingScope($user)) {
            return $this->hospitalDetailChoicesByKey[$cacheKey] = [];
        }
        if (!$useBenchmarkingPermission && !$this->hospitalAccess->canUseMyHospitalsScope($user)) {
            return $this->hospitalDetailChoicesByKey[$cacheKey] = [];
        }

        $hospitals = $useBenchmarkingPermission
            ? $this->hospitalRepository->findAccessibleParticipatingHospitalSummaries($user, HospitalPermission::Benchmarking)
            : $this->hospitalRepository->findAccessibleParticipatingHospitalSummaries($user);

        if (\count($hospitals) <= 1) {
            return $this->hospitalDetailChoicesByKey[$cacheKey] = [];
        }

        $choices = [
            '' => $this->translator->trans('stats.filter.hospital.all_hospitals', [], 'statistics', $locale),
        ];
        foreach ($hospitals as $row) {
            $choices[(string) $row['id']] = $row['name'];
        }

        return $this->hospitalDetailChoicesByKey[$cacheKey] = $choices;
    }

    /**
     * @return list<int>
     */
    private function eligibleStateIds(StatisticsFilterScopeChoicePolicy $policy): array
    {
        return match ($policy) {
            StatisticsFilterScopeChoicePolicy::RegisteredHospitals => ($this->eligibleStateIdsQuery)(2),
            StatisticsFilterScopeChoicePolicy::AllocationStatistics => $this->projectionScopeQuery->stateIdsWithAtLeastDistinctHospitals(2),
        };
    }

    /**
     * @return list<int>
     */
    private function eligibleDispatchAreaIds(StatisticsFilterScopeChoicePolicy $policy): array
    {
        return match ($policy) {
            StatisticsFilterScopeChoicePolicy::RegisteredHospitals => ($this->eligibleDispatchAreaIdsQuery)(2),
            StatisticsFilterScopeChoicePolicy::AllocationStatistics => $this->projectionScopeQuery->dispatchAreaIdsWithAtLeastDistinctHospitals(2),
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

    /**
     * @param list<string>             $keys
     * @param callable(string): string $labelForKey
     *
     * @return array<string, string>
     */
    private function labeledChoices(array $keys, callable $labelForKey): array
    {
        $choices = [];
        foreach ($keys as $key) {
            $choices[$key] = $labelForKey($key);
        }

        return $choices;
    }

    /**
     * @return list<string>
     */
    private function numericStringKeys(int $from, int $to): array
    {
        $keys = [];
        for ($value = $from; $value <= $to; ++$value) {
            $keys[] = (string) $value;
        }

        return $keys;
    }
}
