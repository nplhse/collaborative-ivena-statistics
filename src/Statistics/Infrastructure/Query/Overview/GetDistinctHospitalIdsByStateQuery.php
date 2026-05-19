<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\Overview;

use App\Statistics\Infrastructure\Entity\ProjectionHospitalDimension;
use Doctrine\ORM\EntityManagerInterface;

final readonly class GetDistinctHospitalIdsByStateQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OverviewMaterializedViewsInstaller $materializedViewsInstaller,
    ) {
    }

    /**
     * @return list<int>
     */
    public function __invoke(int $stateId): array
    {
        $this->materializedViewsInstaller->ensureInstalled();

        /** @var list<int|string> $raw */
        $raw = $this->entityManager->createQueryBuilder()
            ->from(ProjectionHospitalDimension::class, 'h')
            ->select('h.hospitalId')
            ->andWhere('h.stateId = :stateId')
            ->setParameter('stateId', $stateId)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(static fn (int|string $id): int => (int) $id, $raw);
    }
}
