<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Repository;

use App\Statistics\Domain\Entity\FavoriteAnalysisView;
use App\Statistics\Domain\Entity\SavedAnalysisView;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisViewSource;
use App\User\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FavoriteAnalysisView>
 */
final class FavoriteAnalysisViewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FavoriteAnalysisView::class);
    }

    public function save(FavoriteAnalysisView $favorite): void
    {
        $this->getEntityManager()->persist($favorite);
        $this->getEntityManager()->flush();
    }

    public function remove(FavoriteAnalysisView $favorite): void
    {
        $this->getEntityManager()->remove($favorite);
        $this->getEntityManager()->flush();
    }

    public function findSystemFavorite(User $user, string $systemViewKey): ?FavoriteAnalysisView
    {
        return $this->findOneBy([
            'user' => $user,
            'source' => AnalysisViewSource::System,
            'systemViewKey' => $systemViewKey,
        ]);
    }

    public function findSavedFavorite(User $user, SavedAnalysisView $savedView): ?FavoriteAnalysisView
    {
        return $this->findOneBy([
            'user' => $user,
            'source' => AnalysisViewSource::Saved,
            'savedView' => $savedView,
        ]);
    }

    /**
     * @return list<FavoriteAnalysisView>
     */
    public function findForUserOrdered(User $user): array
    {
        /** @var list<FavoriteAnalysisView> $items */
        $items = $this->createQueryBuilder('f')
            ->andWhere('f.user = :user')
            ->setParameter('user', $user)
            ->orderBy('f.sortOrder', 'ASC')
            ->addOrderBy('f.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $items;
    }
}
