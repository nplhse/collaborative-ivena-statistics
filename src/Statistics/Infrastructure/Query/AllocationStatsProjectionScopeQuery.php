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

        return array_map(static fn (int|string $id): int => (int) $id, $raw);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array{location:int,tier:int}|null
     */
    public function dominantLocationTierForHospitalIds(array $hospitalIds): ?array
    {
        if ([] === $hospitalIds) {
            return null;
        }

        /** @var array{location:numeric-string|int,tier:numeric-string|int}|null $row */
        $row = $this->entityManager->createQueryBuilder()
            ->from(AllocationStatsProjection::class, 'p')
            ->select('p.hospitalLocationCode AS location', 'p.hospitalTierCode AS tier')
            ->addSelect('COUNT(p.id) AS hit_count')
            ->andWhere('p.hospitalId IN (:hospitalIds)')
            ->andWhere('p.hospitalLocationCode IS NOT NULL')
            ->andWhere('p.hospitalTierCode IS NOT NULL')
            ->groupBy('location', 'tier')
            ->orderBy('hit_count', 'DESC')
            ->setMaxResults(1)
            ->setParameter('hospitalIds', $hospitalIds)
            ->getQuery()
            ->getOneOrNullResult();

        if (!\is_array($row)) {
            return null;
        }

        return [
            'location' => (int) $row['location'],
            'tier' => (int) $row['tier'],
        ];
    }

    /**
     * @return list<int>
     */
    public function distinctHospitalIdsForState(int $stateId): array
    {
        /** @var list<int|string> $raw */
        $raw = $this->entityManager->createQueryBuilder()
            ->from(AllocationStatsProjection::class, 'p')
            ->select('DISTINCT p.hospitalId')
            ->andWhere('p.stateId = :stateId')
            ->setParameter('stateId', $stateId)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(static fn (int|string $id): int => (int) $id, $raw);
    }
}
