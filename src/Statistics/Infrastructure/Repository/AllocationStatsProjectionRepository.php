<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Repository;

use App\Statistics\Infrastructure\Entity\AllocationStatsProjection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AllocationStatsProjection>
 */
final class AllocationStatsProjectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AllocationStatsProjection::class);
    }
}
