<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Repository;

use App\Statistics\Domain\Entity\SavedExplorerView;
use App\Statistics\Domain\Entity\SavedExplorerViewFavorite;
use App\User\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SavedExplorerViewFavorite>
 */
final class SavedExplorerViewFavoriteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SavedExplorerViewFavorite::class);
    }

    public function save(SavedExplorerViewFavorite $favorite): void
    {
        $this->getEntityManager()->persist($favorite);
        $this->getEntityManager()->flush();
    }

    public function remove(SavedExplorerViewFavorite $favorite): void
    {
        $this->getEntityManager()->remove($favorite);
        $this->getEntityManager()->flush();
    }

    public function findForUserAndView(User $user, SavedExplorerView $view): ?SavedExplorerViewFavorite
    {
        $favorite = $this->findOneBy([
            'user' => $user,
            'savedView' => $view,
        ]);

        return $favorite instanceof SavedExplorerViewFavorite ? $favorite : null;
    }

    /**
     * @return list<SavedExplorerView>
     */
    public function findViewsForUserOrdered(User $user): array
    {
        /** @var list<SavedExplorerView> $items */
        $items = $this->getEntityManager()->createQueryBuilder()
            ->select('v')
            ->from(SavedExplorerView::class, 'v')
            ->innerJoin(SavedExplorerViewFavorite::class, 'f', 'WITH', 'f.savedView = v AND IDENTITY(f.user) = :userId')
            ->setParameter('userId', $user->getId(), Types::INTEGER)
            ->orderBy('v.title', 'ASC')
            ->getQuery()
            ->getResult();

        return $items;
    }
}
