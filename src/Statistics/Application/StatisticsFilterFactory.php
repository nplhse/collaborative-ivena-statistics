<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Statistics\Application\Cohort\HospitalCohortEligibilityChecker;
use App\Statistics\Application\Cohort\HospitalCohortResolver;
use App\Statistics\Application\Cohort\HospitalCohortType;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterNotice;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\User\Domain\Entity\User;
use Symfony\Component\HttpFoundation\Request;

final readonly class StatisticsFilterFactory
{
    public function __construct(
        private HospitalRepository $hospitalRepository,
        private HospitalCohortResolver $hospitalCohortResolver,
        private HospitalCohortEligibilityChecker $hospitalCohortEligibilityChecker,
    ) {
    }

    public function createFromRequest(Request $request, ?User $user): StatisticsFilter
    {
        $hasScopeQueryParameter = $request->query->has('scope');
        $explicitMyHospitalsInUrl = $hasScopeQueryParameter
            && StatisticsFilterScope::MyHospitals === StatisticsFilterScope::tryFrom($request->query->getString('scope'));
        $scope = $this->parseScope($request->query->getString('scope', StatisticsFilterScope::MyHospitals->value));
        $period = $this->parsePeriod($request->query->getString('period', StatisticsFilterPeriod::All->value));
        $cohortType = $this->parseCohortType($request->query->getString('cohort'));
        $notice = null;
        $requiresPublicRedirect = false;

        if (!$user instanceof User && StatisticsFilterScope::MyHospitals === $scope) {
            $scope = StatisticsFilterScope::Public;
        }

        $hospitalId = $this->parsePositiveInt($request->query->get('hospital'));
        if (StatisticsFilterScope::Hospital === $scope && (null === $hospitalId || $hospitalId <= 0)) {
            $scope = StatisticsFilterScope::MyHospitals;
            $hospitalId = null;
        }

        if (StatisticsFilterScope::Hospital === $scope && $user instanceof User && null !== $hospitalId && !$this->userMayUseHospital($user, $hospitalId)) {
            $scope = StatisticsFilterScope::MyHospitals;
            $hospitalId = null;
        }

        if (StatisticsFilterScope::Hospital !== $scope) {
            $hospitalId = null;
        }

        if (StatisticsFilterScope::HospitalCohort !== $scope) {
            $cohortType = null;
        } elseif (null === $cohortType) {
            $scope = StatisticsFilterScope::MyHospitals;
        } else {
            $cohort = $this->hospitalCohortResolver->resolve($cohortType);
            if (!$this->hospitalCohortEligibilityChecker->hasMinimumParticipants($cohort)) {
                $scope = StatisticsFilterScope::Public;
                $cohortType = null;
                $notice = StatisticsFilterNotice::CohortTooSmall;
                $requiresPublicRedirect = true;
            }
        }

        $referenceYear = $this->parsePositiveInt($request->query->get('year'));
        $referenceMonth = $this->parsePositiveInt($request->query->get('month'));

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

        if ($user instanceof User && StatisticsFilterScope::MyHospitals === $scope && !$this->userHasAnyAccessibleHospital($user) && !$explicitMyHospitalsInUrl) {
            $scope = StatisticsFilterScope::Public;
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
        );
    }

    private function parseScope(string $raw): StatisticsFilterScope
    {
        return StatisticsFilterScope::tryFrom($raw) ?? StatisticsFilterScope::MyHospitals;
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

    private function userHasAnyAccessibleHospital(User $user): bool
    {
        return $this->hospitalRepository->countAccessibleHospitals($user) > 0;
    }

    private function userMayUseHospital(User $user, int $hospitalId): bool
    {
        $allowedIds = $this->hospitalRepository
            ->getQueryBuilderForAccessibleHospitals($user)
            ->select('h.id')
            ->getQuery()
            ->getSingleColumnResult();

        return array_any($allowedIds, fn ($id): bool => (int) $id === $hospitalId);
    }
}
