<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Repository;

use App\User\Domain\Entity\UserActivity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserActivity>
 */
final class UserActivityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserActivity::class);
    }

    public function existsWithDeduplicationKey(string $deduplicationKey): bool
    {
        return $this->findOneByDeduplicationKey($deduplicationKey) instanceof UserActivity;
    }

    public function findOneByDeduplicationKey(string $deduplicationKey): ?UserActivity
    {
        return $this->findOneBy(['deduplicationKey' => $deduplicationKey]);
    }
}
