<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterInput;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use Symfony\Component\HttpFoundation\InputBag;

final class ComparisonFilterInputFactory
{
    /**
     * @param InputBag<string> $query
     */
    public function fromQuery(
        InputBag $query,
        StatisticsFilter $primaryFilter,
        string $defaultCohort,
    ): StatisticsFilterInput {
        $scopeRaw = $query->getString('comparison_scope');
        $cohortRaw = $query->getString('comparison_cohort');
        $stateRaw = $query->getString('comparison_state');
        $dispatchAreaRaw = $query->getString('comparison_dispatch_area');
        [$normalizedScope, $normalizedCohort, $normalizedState, $normalizedDispatchArea] = $this->normalizeScope(
            $scopeRaw,
            $cohortRaw,
            $stateRaw,
            $dispatchAreaRaw,
        );

        $period = StatisticsFilterPeriod::tryFrom($query->getString('comparison_period')) ?? $primaryFilter->period;
        $year = $query->has('comparison_year') ? $query->getInt('comparison_year') : $primaryFilter->referenceYear;
        $month = $query->has('comparison_month') ? $query->getInt('comparison_month') : $primaryFilter->referenceMonth;

        if (StatisticsFilterScope::Public === $normalizedScope) {
            return new StatisticsFilterInput(
                StatisticsFilterScope::Public->value,
                '',
                '',
                '',
                '',
                $period->value,
                $year,
                $month,
                true,
            );
        }

        if (StatisticsFilterScope::State === $normalizedScope && null !== $normalizedState) {
            return new StatisticsFilterInput(
                StatisticsFilterScope::State->value,
                '',
                '',
                (string) $normalizedState,
                '',
                $period->value,
                $year,
                $month,
                true,
            );
        }

        if (StatisticsFilterScope::DispatchArea === $normalizedScope && null !== $normalizedDispatchArea) {
            return new StatisticsFilterInput(
                StatisticsFilterScope::DispatchArea->value,
                '',
                '',
                '',
                (string) $normalizedDispatchArea,
                $period->value,
                $year,
                $month,
                true,
            );
        }

        return new StatisticsFilterInput(
            StatisticsFilterScope::HospitalCohort->value,
            '',
            $normalizedCohort ?? $defaultCohort,
            '',
            '',
            $period->value,
            $year,
            $month,
            true,
        );
    }

    /**
     * @return array{0: StatisticsFilterScope, 1: string|null, 2: int|null, 3: int|null}
     */
    private function normalizeScope(string $scopeRaw, string $cohortRaw, string $stateRaw, string $dispatchAreaRaw): array
    {
        $normalizedScope = StatisticsFilterScope::tryFrom($scopeRaw) ?? StatisticsFilterScope::HospitalCohort;
        $normalizedCohort = '' !== $cohortRaw ? $cohortRaw : null;
        $normalizedState = '' !== $stateRaw ? (int) $stateRaw : null;
        $normalizedDispatchArea = '' !== $dispatchAreaRaw ? (int) $dispatchAreaRaw : null;

        if (!str_contains($scopeRaw, ':')) {
            return [$normalizedScope, $normalizedCohort, $normalizedState, $normalizedDispatchArea];
        }

        [$token, $operand] = array_pad(explode(':', $scopeRaw, 2), 2, '');
        $token = trim($token);
        $operand = trim($operand);

        if (StatisticsFilterScope::HospitalCohort->value === $token && '' !== $operand && null === $normalizedCohort) {
            return [StatisticsFilterScope::HospitalCohort, $operand, $normalizedState, $normalizedDispatchArea];
        }
        if (StatisticsFilterScope::State->value === $token && '' !== $operand) {
            return [StatisticsFilterScope::State, $normalizedCohort, (int) $operand, $normalizedDispatchArea];
        }
        if (StatisticsFilterScope::DispatchArea->value === $token && '' !== $operand) {
            return [StatisticsFilterScope::DispatchArea, $normalizedCohort, $normalizedState, (int) $operand];
        }
        if (StatisticsFilterScope::Public->value === $token) {
            return [StatisticsFilterScope::Public, $normalizedCohort, $normalizedState, $normalizedDispatchArea];
        }

        return [$normalizedScope, $normalizedCohort, $normalizedState, $normalizedDispatchArea];
    }
}
