<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\Overview;

use App\Statistics\Infrastructure\Entity\ProjectionHospitalDimension;
use Doctrine\ORM\EntityManagerInterface;

final readonly class GetDistinctHospitalIdsByDispatchAreaQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OverviewMaterializedViewsInstaller $materializedViewsInstaller,
    ) {
    }

    /**
     * @return list<int>
     */
    public function __invoke(int $dispatchAreaId): array
    {
        $this->materializedViewsInstaller->ensureInstalled();

        /** @var list<int|string> $raw */
        $raw = $this->entityManager->createQueryBuilder()
            ->from(ProjectionHospitalDimension::class, 'h')
            ->select('h.hospitalId')
            ->andWhere('h.dispatchAreaId = :dispatchAreaId')
            ->orderBy('h.hospitalId', 'ASC')
            ->setParameter('dispatchAreaId', $dispatchAreaId)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(static fn (int|string $id): int => (int) $id, $raw);
    }
}
