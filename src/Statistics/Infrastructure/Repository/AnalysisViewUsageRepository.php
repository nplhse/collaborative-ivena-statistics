<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Repository;

use App\Statistics\Domain\Entity\AnalysisViewUsage;
use App\Statistics\Domain\Entity\SavedAnalysisView;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisViewSource;
use App\User\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnalysisViewUsage>
 */
final class AnalysisViewUsageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnalysisViewUsage::class);
    }

    public function save(AnalysisViewUsage $usage): void
    {
        $this->getEntityManager()->persist($usage);
        $this->getEntityManager()->flush();
    }

    public function findSystemUsage(User $user, string $systemViewKey): ?AnalysisViewUsage
    {
        return $this->findOneBy([
            'user' => $user,
            'source' => AnalysisViewSource::System,
            'systemViewKey' => $systemViewKey,
        ]);
    }

    public function findSavedUsage(User $user, SavedAnalysisView $savedView): ?AnalysisViewUsage
    {
        return $this->findOneBy([
            'user' => $user,
            'source' => AnalysisViewSource::Saved,
            'savedView' => $savedView,
        ]);
    }

    /**
     * @return list<AnalysisViewUsage>
     */
    public function findLastUsedForUser(User $user, int $limit = 10): array
    {
        /** @var list<AnalysisViewUsage> $items */
        $items = $this->createQueryBuilder('u')
            ->andWhere('u.user = :user')
            ->setParameter('user', $user)
            ->orderBy('u.lastUsedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $items;
    }

    /**
     * @return list<AnalysisViewUsage>
     */
    public function findMostFrequentForUser(User $user, int $limit = 10): array
    {
        /** @var list<AnalysisViewUsage> $items */
        $items = $this->createQueryBuilder('u')
            ->andWhere('u.user = :user')
            ->setParameter('user', $user)
            ->orderBy('u.useCount', 'DESC')
            ->addOrderBy('u.lastUsedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $items;
    }
}
