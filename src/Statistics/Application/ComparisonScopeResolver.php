<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Statistics\Application\Cohort\HospitalCohortResolver;
use App\Statistics\Application\Cohort\HospitalCohortType;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Infrastructure\Query\AllocationStatsProjectionScopeQuery;
use App\User\Domain\Entity\User;
use Symfony\Component\HttpFoundation\Request;

final readonly class ComparisonScopeResolver
{
    public function __construct(
        private StatisticsFilterFactory $statisticsFilterFactory,
        private HospitalRepository $hospitalRepository,
        private HospitalCohortResolver $hospitalCohortResolver,
        private AllocationStatsProjectionScopeQuery $projectionScopeQuery,
    ) {
    }

    public function resolve(Request $request, ?User $user, StatisticsFilter $primaryFilter): StatisticsFilter
    {
        $comparisonScopeRaw = $request->query->getString('comparison_scope');
        $comparisonCohortRaw = $request->query->getString('comparison_cohort');
        $comparisonStateRaw = $request->query->getString('comparison_state');
        $comparisonPeriodRaw = $request->query->getString('comparison_period');
        $normalized = $this->normalizeComparisonScope($comparisonScopeRaw, $comparisonCohortRaw);
        $comparisonCohort = '' !== $normalized['cohort'] ? $normalized['cohort'] : $this->defaultComparisonCohort($primaryFilter, $user);
        $comparisonStateId = '' !== $normalized['state'] ? (int) $normalized['state'] : ('' !== $comparisonStateRaw ? (int) $comparisonStateRaw : null);
        $comparisonPeriod = StatisticsFilterPeriod::tryFrom($comparisonPeriodRaw) ?? $primaryFilter->period;
        $comparisonYear = $request->query->has('comparison_year')
            ? $request->query->getInt('comparison_year')
            : $primaryFilter->referenceYear;
        $comparisonMonth = $request->query->has('comparison_month')
            ? $request->query->getInt('comparison_month')
            : $primaryFilter->referenceMonth;

        $comparisonScope = $normalized['scope'];
        $comparisonQuery = ['period' => $comparisonPeriod->value];
        if (StatisticsFilterScope::Public->value === $comparisonScope) {
            $comparisonQuery['scope'] = StatisticsFilterScope::Public->value;
        } elseif (StatisticsFilterScope::State->value === $comparisonScope && null !== $comparisonStateId && $comparisonStateId > 0) {
            $comparisonQuery['scope'] = StatisticsFilterScope::State->value;
            $comparisonQuery['state'] = (string) $comparisonStateId;
        } else {
            $comparisonQuery['scope'] = StatisticsFilterScope::HospitalCohort->value;
            $comparisonQuery['cohort'] = $comparisonCohort;
        }
        if ((StatisticsFilterPeriod::Year === $comparisonPeriod || StatisticsFilterPeriod::Month === $comparisonPeriod) && (null !== $comparisonYear && $comparisonYear > 0)) {
            $comparisonQuery['year'] = (string) $comparisonYear;
        }
        if (StatisticsFilterPeriod::Month === $comparisonPeriod && (null !== $comparisonMonth && $comparisonMonth > 0)) {
            $comparisonQuery['month'] = (string) $comparisonMonth;
        }

        $comparisonRequest = new Request(query: $comparisonQuery);

        return $this->statisticsFilterFactory->createFromRequest($comparisonRequest, $user);
    }

    /**
     * @return array{scope:string,cohort:string,state:string}
     */
    private function normalizeComparisonScope(string $scopeRaw, string $cohortRaw): array
    {
        $stateRaw = '';
        if (str_contains($scopeRaw, ':')) {
            [$scopeToken, $operand] = array_pad(explode(':', $scopeRaw, 2), 2, '');
            if (StatisticsFilterScope::HospitalCohort->value === trim($scopeToken) && '' === $cohortRaw) {
                $cohortRaw = trim($operand);
                $scopeRaw = StatisticsFilterScope::HospitalCohort->value;
            } elseif (StatisticsFilterScope::State->value === trim($scopeToken)) {
                $stateRaw = trim($operand);
                $scopeRaw = StatisticsFilterScope::State->value;
            } elseif (StatisticsFilterScope::Public->value === trim($scopeToken)) {
                $scopeRaw = StatisticsFilterScope::Public->value;
            }
        }

        return [
            'scope' => $scopeRaw,
            'cohort' => $cohortRaw,
            'state' => $stateRaw,
        ];
    }

    private function defaultComparisonCohort(StatisticsFilter $primaryFilter, ?User $user): string
    {
        if (StatisticsFilterScope::HospitalCohort === $primaryFilter->scope && $primaryFilter->cohortType instanceof HospitalCohortType) {
            return $primaryFilter->cohortType->value;
        }

        $hospitalIds = match ($primaryFilter->scope) {
            StatisticsFilterScope::Hospital => null !== $primaryFilter->hospitalId ? [$primaryFilter->hospitalId] : [],
            StatisticsFilterScope::MyHospitals => $this->accessibleHospitalIds($user),
            default => [],
        };

        if ([] === $hospitalIds) {
            return HospitalCohortType::cases()[0]->value;
        }

        $dominant = $this->projectionScopeQuery->dominantLocationTierForHospitalIds($hospitalIds);
        if (!\is_array($dominant)) {
            return HospitalCohortType::cases()[0]->value;
        }

        foreach (HospitalCohortType::cases() as $candidate) {
            $cohort = $this->hospitalCohortResolver->resolve($candidate);
            if (\in_array($dominant['location'], $cohort->locationCodeValues(), true) && \in_array($dominant['tier'], $cohort->tierCodeValues(), true)) {
                return $candidate->value;
            }
        }

        return HospitalCohortType::cases()[0]->value;
    }

    /**
     * @return list<int>
     */
    private function accessibleHospitalIds(?User $user): array
    {
        if (!$user instanceof User) {
            return [];
        }

        /** @var list<int|string> $rawIds */
        $rawIds = $this->hospitalRepository
            ->getQueryBuilderForAccessibleHospitals($user)
            ->select('h.id')
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(static fn (int|string $id): int => (int) $id, $rawIds);
    }
}
