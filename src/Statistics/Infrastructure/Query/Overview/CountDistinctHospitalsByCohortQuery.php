<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\Overview;

use App\Statistics\Application\Cohort\HospitalCohort;
use App\Statistics\Infrastructure\Entity\ProjectionHospitalDimension;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CountDistinctHospitalsByCohortQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OverviewMaterializedViewsInstaller $materializedViewsInstaller,
    ) {
    }

    public function __invoke(HospitalCohort $cohort): int
    {
        $this->materializedViewsInstaller->ensureInstalled();

        return (int) $this->entityManager->createQueryBuilder()
            ->from(ProjectionHospitalDimension::class, 'h')
            ->select('COUNT(h.hospitalId)')
            ->andWhere('h.hospitalLocationCode IN (:locationCodes)')
            ->andWhere('h.hospitalTierCode IN (:tierCodes)')
            ->setParameter('locationCodes', $cohort->locationCodeValues())
            ->setParameter('tierCodes', $cohort->tierCodeValues())
            ->getQuery()
            ->getSingleScalarResult();
    }
}
