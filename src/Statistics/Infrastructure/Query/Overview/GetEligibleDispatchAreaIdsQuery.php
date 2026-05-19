<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\Overview;

use App\Statistics\Infrastructure\Entity\ProjectionDispatchAreaHospitalCount;
use Doctrine\ORM\EntityManagerInterface;

final readonly class GetEligibleDispatchAreaIdsQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OverviewMaterializedViewsInstaller $materializedViewsInstaller,
    ) {
    }

    /**
     * @return list<int>
     */
    public function __invoke(int $minimumParticipants = 2): array
    {
        $this->materializedViewsInstaller->ensureInstalled();

        /** @var list<int|string> $raw */
        $raw = $this->entityManager->createQueryBuilder()
            ->from(ProjectionDispatchAreaHospitalCount::class, 'd')
            ->select('d.dispatchAreaId')
            ->andWhere('d.hospitalCount >= :min')
            ->orderBy('d.dispatchAreaId', 'ASC')
            ->setParameter('min', $minimumParticipants)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(static fn (int|string $id): int => (int) $id, $raw);
    }
}
