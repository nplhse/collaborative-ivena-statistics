<?php

declare(strict_types=1);

namespace App\Feedback\Infrastructure\Repository;

use App\Feedback\Domain\Entity\Feedback;
use App\Feedback\Domain\Enum\FeedbackStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Feedback>
 */
final class FeedbackRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Feedback::class);
    }

    /** Count of feedback items that are not marked as done. */
    public function countOpen(): int
    {
        /** @var int|string $count */
        $count = $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('f.status != :done')
            ->setParameter('done', FeedbackStatus::DONE)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }
}
