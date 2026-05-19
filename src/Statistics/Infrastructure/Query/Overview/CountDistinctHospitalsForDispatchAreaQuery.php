<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\Overview;

use App\Statistics\Infrastructure\Entity\ProjectionDispatchAreaHospitalCount;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CountDistinctHospitalsForDispatchAreaQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OverviewMaterializedViewsInstaller $materializedViewsInstaller,
    ) {
    }

    public function __invoke(int $dispatchAreaId): int
    {
        $this->materializedViewsInstaller->ensureInstalled();

        $row = $this->entityManager->find(ProjectionDispatchAreaHospitalCount::class, $dispatchAreaId);

        return $row?->getHospitalCount() ?? 0;
    }
}
