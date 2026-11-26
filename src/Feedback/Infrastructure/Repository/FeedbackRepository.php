<?php

declare(strict_types=1);

namespace App\Feedback\Infrastructure\Repository;

use App\Feedback\Domain\Entity\Feedback;
use App\Feedback\Domain\Enum\FeedbackClassification;
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

    /** Count of normal feedback items that are not marked as done; quarantined entries never count. */
    public function countOpen(): int
    {
        /** @var int|string $count */
        $count = $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('f.status != :done')
            ->andWhere('f.classification = :normal')
            ->setParameter('done', FeedbackStatus::DONE)
            ->setParameter('normal', FeedbackClassification::NORMAL)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    /** Count of quarantined feedback items, regardless of their workflow status. */
    public function countQuarantined(): int
    {
        /** @var int|string $count */
        $count = $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('f.classification = :quarantined')
            ->setParameter('quarantined', FeedbackClassification::QUARANTINED)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }
}
