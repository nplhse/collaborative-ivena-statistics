<?php

namespace App\Repository;

use App\Entity\IndicationRaw;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndicationRaw>
 */
class IndicationRawRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndicationRaw::class);
    }

    /**
     * @return array<int,array{hash:string,id:int,normalized_id:int}>
     */
    public function preloadAllLight(): array
    {
        return $this->createQueryBuilder('r')
            ->select('r.hash AS hash, r.id AS id, IDENTITY(r.normalized) AS normalized_id')
            ->getQuery()
            ->getArrayResult();
    }
}
