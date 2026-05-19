<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\Overview;

use App\Statistics\Application\Cohort\HospitalCohort;
use App\Statistics\Infrastructure\Entity\ProjectionHospitalDimension;
use Doctrine\ORM\EntityManagerInterface;

final readonly class GetDistinctHospitalIdsByCohortQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OverviewMaterializedViewsInstaller $materializedViewsInstaller,
    ) {
    }

    /**
     * @return list<int>
     */
    public function __invoke(HospitalCohort $cohort): array
    {
        $this->materializedViewsInstaller->ensureInstalled();

        /** @var list<int|string> $raw */
        $raw = $this->entityManager->createQueryBuilder()
            ->from(ProjectionHospitalDimension::class, 'h')
            ->select('h.hospitalId')
            ->andWhere('h.hospitalLocationCode IN (:locationCodes)')
            ->andWhere('h.hospitalTierCode IN (:tierCodes)')
            ->setParameter('locationCodes', $cohort->locationCodeValues())
            ->setParameter('tierCodes', $cohort->tierCodeValues())
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(static fn (int|string $id): int => (int) $id, $raw);
    }
}
