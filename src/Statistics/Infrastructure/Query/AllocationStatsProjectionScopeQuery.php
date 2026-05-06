<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query;

use App\Statistics\Application\Cohort\HospitalCohort;
use App\Statistics\Infrastructure\Entity\AllocationStatsProjection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AllocationStatsProjectionScopeQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function countDistinctHospitalsForCohort(HospitalCohort $cohort): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->from(AllocationStatsProjection::class, 'p')
            ->select('COUNT(DISTINCT p.hospitalId)')
            ->andWhere('p.hospitalLocationCode IN (:locationCodes)')
            ->andWhere('p.hospitalTierCode IN (:tierCodes)')
            ->setParameter('locationCodes', $cohort->locationCodeValues())
            ->setParameter('tierCodes', $cohort->tierCodeValues())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<int>
     */
    public function distinctHospitalIdsForCohort(HospitalCohort $cohort): array
    {
        /** @var list<int|string> $raw */
        $raw = $this->entityManager->createQueryBuilder()
            ->from(AllocationStatsProjection::class, 'p')
            ->select('DISTINCT p.hospitalId')
            ->andWhere('p.hospitalLocationCode IN (:locationCodes)')
            ->andWhere('p.hospitalTierCode IN (:tierCodes)')
            ->setParameter('locationCodes', $cohort->locationCodeValues())
            ->setParameter('tierCodes', $cohort->tierCodeValues())
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(static fn (int|string $id): int => (int) $id, $raw));
    }
}
