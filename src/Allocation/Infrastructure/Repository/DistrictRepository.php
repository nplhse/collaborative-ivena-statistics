<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Repository;

use App\Allocation\Domain\Entity\District;
use App\Allocation\Domain\Entity\DistrictGeometry;
use App\Shared\Infrastructure\Repository\PublicIdRepositoryTrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<District>
 */
final class DistrictRepository extends ServiceEntityRepository
{
    use PublicIdRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, District::class);
    }

    /**
     * Loads the boundary on demand; `District` has no relation to it, so lists never hydrate polygons.
     */
    public function findGeometry(District $district): ?DistrictGeometry
    {
        return $this->getEntityManager()->find(DistrictGeometry::class, $district->getId());
    }
}
