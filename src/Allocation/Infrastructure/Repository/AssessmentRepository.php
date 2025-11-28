<?php

namespace App\Allocation\Infrastructure\Repository;

use App\Allocation\Domain\Entity\Assessment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Assessment>
 */
final class AssessmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Assessment::class);
    }
}
