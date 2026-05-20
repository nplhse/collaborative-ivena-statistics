<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Statistics\Application\Cohort\HospitalCohortEligibilityChecker;
use App\Statistics\Application\Cohort\HospitalCohortResolver;
use App\Statistics\Application\Cohort\HospitalCohortType;
use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterInput;
use App\Statistics\Application\DTO\StatisticsFilterNotice;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Infrastructure\Query\Overview\CountDistinctHospitalsForDispatchAreaQuery;
use App\Statistics\Infrastructure\Query\Overview\CountDistinctHospitalsForStateQuery;
use App\User\Domain\Entity\User;

final readonly class StatisticsFilterFactory
{
    public function __construct(
        private HospitalAccessInterface $hospitalAccess,
        private HospitalCohortResolver $hospitalCohortResolver,
        private HospitalCohortEligibilityChecker $hospitalCohortEligibilityChecker,
        private CountDistinctHospitalsForStateQuery $countDistinctHospitalsForStateQuery,
        private CountDistinctHospitalsForDispatchAreaQuery $countDistinctHospitalsForDispatchAreaQuery,
    ) {
    }

    public function createFromInput(StatisticsFilterInput $input, ?User $user): StatisticsFilter
    {
        $normalized = $this->normalizeScopeInput(
            $input->scope,
            $input->hospital,
            $input->cohort,
            $input->state,
            $input->dispatchArea,
        );
        $explicitMyHospitalsInUrl = $input->hasScopeQueryParameter
            && StatisticsFilterScope::MyHospitals === StatisticsFilterScope::tryFrom($normalized['scope']);
        $scope = $this->parseScope($normalized['scope']);
        $period = $this->parsePeriod($input->period);
        $cohortType = $this->parseCohortType($normalized['cohort']);
        $notice = null;
        $requiresPublicRedirect = false;

        if (!$user instanceof User && StatisticsFilterScope::MyHospitals === $scope) {
            $scope = StatisticsFilterScope::Public;
        }

        $hospitalId = $this->parsePositiveInt($normalized['hospital']);
        $stateId = $this->parsePositiveInt($normalized['state']);
        $dispatchAreaId = $this->parsePositiveInt($normalized['dispatch_area']);
        if (StatisticsFilterScope::Hospital === $scope && (null === $hospitalId || $hospitalId <= 0)) {
            $scope = StatisticsFilterScope::MyHospitals;
            $hospitalId = null;
        }

        if (StatisticsFilterScope::Hospital === $scope && $user instanceof User && null !== $hospitalId && !$this->hospitalAccess->canSelectHospitalScope($user, $hospitalId)) {
            if ($this->hospitalAccess->canUseMyHospitalsScope($user)) {
                $scope = StatisticsFilterScope::MyHospitals;
            } else {
                $scope = StatisticsFilterScope::Public;
                $requiresPublicRedirect = true;
            }
            $hospitalId = null;
        }

        if (StatisticsFilterScope::Hospital !== $scope) {
            $hospitalId = null;
        }
        if (StatisticsFilterScope::State !== $scope) {
            $stateId = null;
        }
        if (StatisticsFilterScope::DispatchArea !== $scope) {
            $dispatchAreaId = null;
        }

        if (StatisticsFilterScope::HospitalCohort !== $scope) {
            $cohortType = null;
        } elseif (!$cohortType instanceof HospitalCohortType) {
            $scope = $user instanceof User && $this->hospitalAccess->canUseMyHospitalsScope($user)
                ? StatisticsFilterScope::MyHospitals
                : StatisticsFilterScope::Public;
        } else {
            $cohort = $this->hospitalCohortResolver->resolve($cohortType);
            if (!$this->hospitalCohortEligibilityChecker->hasMinimumParticipants($cohort)) {
                $scope = StatisticsFilterScope::Public;
                $cohortType = null;
                $notice = StatisticsFilterNotice::CohortTooSmall;
                $requiresPublicRedirect = true;
            }
        }

        if (StatisticsFilterScope::State === $scope && (null === $stateId || ($this->countDistinctHospitalsForStateQuery)($stateId) < 2)) {
            $scope = StatisticsFilterScope::Public;
            $stateId = null;
            $notice = StatisticsFilterNotice::StateInvalid;
            $requiresPublicRedirect = true;
        }

        if (StatisticsFilterScope::DispatchArea === $scope && (null === $dispatchAreaId || ($this->countDistinctHospitalsForDispatchAreaQuery)($dispatchAreaId) < 2)) {
            $scope = StatisticsFilterScope::Public;
            $dispatchAreaId = null;
            $notice = StatisticsFilterNotice::DispatchAreaInvalid;
            $requiresPublicRedirect = true;
        }

        $referenceYear = $this->parsePositiveInt($input->year);
        $referenceMonth = $this->parsePositiveInt($input->month);

        if (StatisticsFilterPeriod::Year === $period) {
            $referenceYear ??= (int) new \DateTimeImmutable()->format('Y');
            $referenceMonth = null;
        } elseif (StatisticsFilterPeriod::Month === $period) {
            $now = new \DateTimeImmutable();
            $referenceYear ??= (int) $now->format('Y');
            $referenceMonth ??= (int) $now->format('n');
            $referenceMonth = max(1, min(12, $referenceMonth));
        } else {
            $referenceYear = null;
            $referenceMonth = null;
        }

        if (StatisticsFilterScope::MyHospitals === $scope && (!$user instanceof User || !$this->hospitalAccess->canUseMyHospitalsScope($user))) {
            $scope = StatisticsFilterScope::Public;
            if ($explicitMyHospitalsInUrl) {
                $requiresPublicRedirect = true;
            }
        }

        if (StatisticsFilterScope::Hospital === $scope && $user instanceof User && null !== $hospitalId && !$this->hospitalAccess->canSelectHospitalScope($user, $hospitalId)) {
            $scope = StatisticsFilterScope::Public;
            $hospitalId = null;
            $requiresPublicRedirect = true;
        }

        if (!$user instanceof User && StatisticsFilterScope::Hospital === $scope) {
            $scope = StatisticsFilterScope::Public;
            $hospitalId = null;
        }

        return new StatisticsFilter(
            $scope,
            $hospitalId,
            $cohortType,
            $period,
            $referenceYear,
            $referenceMonth,
            $notice,
            $requiresPublicRedirect,
            $stateId,
            $dispatchAreaId,
        );
    }

    /**
     * @return array{scope: string, hospital: string, cohort: string, state: string, dispatch_area: string}
     */
    private function normalizeScopeInput(string $scopeRaw, string $hospitalRaw, string $cohortRaw, string $stateRaw, string $dispatchAreaRaw): array
    {
        if (!str_contains($scopeRaw, ':')) {
            return [
                'scope' => $scopeRaw,
                'hospital' => $hospitalRaw,
                'cohort' => $cohortRaw,
                'state' => $stateRaw,
                'dispatch_area' => $dispatchAreaRaw,
            ];
        }

        [$scopeToken, $operand] = array_pad(explode(':', $scopeRaw, 2), 2, '');
        $scopeToken = trim($scopeToken);
        $operand = trim($operand);
        if ('' === $scopeToken || '' === $operand) {
            return [
                'scope' => $scopeRaw,
                'hospital' => $hospitalRaw,
                'cohort' => $cohortRaw,
                'state' => $stateRaw,
                'dispatch_area' => $dispatchAreaRaw,
            ];
        }

        $scope = StatisticsFilterScope::tryFrom($scopeToken);
        if (!$scope instanceof StatisticsFilterScope) {
            return [
                'scope' => $scopeRaw,
                'hospital' => $hospitalRaw,
                'cohort' => $cohortRaw,
                'state' => $stateRaw,
                'dispatch_area' => $dispatchAreaRaw,
            ];
        }

        if (StatisticsFilterScope::Hospital === $scope && '' === $hospitalRaw) {
            $hospitalRaw = $operand;
        }

        if (StatisticsFilterScope::HospitalCohort === $scope && '' === $cohortRaw) {
            $cohortRaw = $operand;
        }
        if (StatisticsFilterScope::State === $scope && '' === $stateRaw) {
            $stateRaw = $operand;
        }
        if (StatisticsFilterScope::DispatchArea === $scope && '' === $dispatchAreaRaw) {
            $dispatchAreaRaw = $operand;
        }

        return [
            'scope' => $scope->value,
            'hospital' => $hospitalRaw,
            'cohort' => $cohortRaw,
            'state' => $stateRaw,
            'dispatch_area' => $dispatchAreaRaw,
        ];
    }

    private function parseScope(string $raw): StatisticsFilterScope
    {
        return StatisticsFilterScope::tryFrom($raw) ?? StatisticsFilterScope::Public;
    }

    private function parsePeriod(string $raw): StatisticsFilterPeriod
    {
        return StatisticsFilterPeriod::tryFrom($raw) ?? StatisticsFilterPeriod::All;
    }

    private function parseCohortType(string $raw): ?HospitalCohortType
    {
        if ('' === $raw) {
            return null;
        }

        return HospitalCohortType::tryFrom($raw);
    }

    private function parsePositiveInt(mixed $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
