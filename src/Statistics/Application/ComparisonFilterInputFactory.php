<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterInput;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
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
        $scopeRaw = $query->getString(StatisticsQueryKeys::COMPARISON_SCOPE);
        $hospitalRaw = $query->getString(StatisticsQueryKeys::COMPARISON_HOSPITAL);
        $cohortRaw = $query->getString(StatisticsQueryKeys::COMPARISON_COHORT);
        $stateRaw = $query->getString(StatisticsQueryKeys::COMPARISON_STATE);
        $dispatchAreaRaw = $query->getString(StatisticsQueryKeys::COMPARISON_DISPATCH_AREA);
        [$normalizedScope, $normalizedHospital, $normalizedCohort, $normalizedState, $normalizedDispatchArea] = $this->normalizeScope(
            $scopeRaw,
            $hospitalRaw,
            $cohortRaw,
            $stateRaw,
            $dispatchAreaRaw,
        );

        $period = StatisticsFilterPeriod::tryFrom($query->getString(StatisticsQueryKeys::COMPARISON_PERIOD)) ?? $primaryFilter->period;
        $year = $query->has(StatisticsQueryKeys::COMPARISON_YEAR) ? $query->getInt(StatisticsQueryKeys::COMPARISON_YEAR) : $primaryFilter->referenceYear;
        $month = $query->has(StatisticsQueryKeys::COMPARISON_MONTH) ? $query->getInt(StatisticsQueryKeys::COMPARISON_MONTH) : $primaryFilter->referenceMonth;
        $quarter = $query->has(StatisticsQueryKeys::COMPARISON_QUARTER) ? $query->getInt(StatisticsQueryKeys::COMPARISON_QUARTER) : $primaryFilter->referenceQuarter;

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
                $quarter,
                true,
            );
        }

        if (StatisticsFilterScope::MyHospitals === $normalizedScope) {
            return new StatisticsFilterInput(
                StatisticsFilterScope::MyHospitals->value,
                '',
                '',
                '',
                '',
                $period->value,
                $year,
                $month,
                $quarter,
                true,
            );
        }

        if (StatisticsFilterScope::Hospital === $normalizedScope) {
            return new StatisticsFilterInput(
                StatisticsFilterScope::Hospital->value,
                $normalizedHospital ?? '',
                '',
                '',
                '',
                $period->value,
                $year,
                $month,
                $quarter,
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
                $quarter,
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
                $quarter,
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
            $quarter,
            true,
        );
    }

    /**
     * @return array{0: StatisticsFilterScope, 1: string|null, 2: string|null, 3: int|null, 4: int|null}
     */
    private function normalizeScope(
        string $scopeRaw,
        string $hospitalRaw,
        string $cohortRaw,
        string $stateRaw,
        string $dispatchAreaRaw,
    ): array {
        $normalizedScope = StatisticsFilterScope::tryFrom($scopeRaw) ?? StatisticsFilterScope::HospitalCohort;
        $normalizedHospital = '' !== $hospitalRaw ? $hospitalRaw : null;
        $normalizedCohort = '' !== $cohortRaw ? $cohortRaw : null;
        $normalizedState = '' !== $stateRaw ? (int) $stateRaw : null;
        $normalizedDispatchArea = '' !== $dispatchAreaRaw ? (int) $dispatchAreaRaw : null;

        if (!str_contains($scopeRaw, ':')) {
            return [$normalizedScope, $normalizedHospital, $normalizedCohort, $normalizedState, $normalizedDispatchArea];
        }

        [$token, $operand] = array_pad(explode(':', $scopeRaw, 2), 2, '');
        $token = trim($token);
        $operand = trim($operand);

        if (StatisticsFilterScope::HospitalCohort->value === $token && '' !== $operand && null === $normalizedCohort) {
            return [StatisticsFilterScope::HospitalCohort, $normalizedHospital, $operand, $normalizedState, $normalizedDispatchArea];
        }
        if (StatisticsFilterScope::State->value === $token && '' !== $operand) {
            return [StatisticsFilterScope::State, $normalizedHospital, $normalizedCohort, (int) $operand, $normalizedDispatchArea];
        }
        if (StatisticsFilterScope::DispatchArea->value === $token && '' !== $operand) {
            return [StatisticsFilterScope::DispatchArea, $normalizedHospital, $normalizedCohort, $normalizedState, (int) $operand];
        }
        if (StatisticsFilterScope::Public->value === $token) {
            return [StatisticsFilterScope::Public, $normalizedHospital, $normalizedCohort, $normalizedState, $normalizedDispatchArea];
        }
        if (StatisticsFilterScope::Hospital->value === $token && '' !== $operand && null === $normalizedHospital) {
            return [StatisticsFilterScope::Hospital, $operand, $normalizedCohort, $normalizedState, $normalizedDispatchArea];
        }

        return [$normalizedScope, $normalizedHospital, $normalizedCohort, $normalizedState, $normalizedDispatchArea];
    }
}
