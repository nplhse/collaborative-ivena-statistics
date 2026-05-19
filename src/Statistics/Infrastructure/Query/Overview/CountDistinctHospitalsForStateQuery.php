<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\Overview;

use App\Statistics\Infrastructure\Entity\ProjectionStateHospitalCount;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CountDistinctHospitalsForStateQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OverviewMaterializedViewsInstaller $materializedViewsInstaller,
    ) {
    }

    public function __invoke(int $stateId): int
    {
        $this->materializedViewsInstaller->ensureInstalled();

        $row = $this->entityManager->find(ProjectionStateHospitalCount::class, $stateId);

        return $row?->getHospitalCount() ?? 0;
    }
}
