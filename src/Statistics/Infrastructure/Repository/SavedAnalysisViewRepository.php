<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Repository;

use App\Statistics\Domain\Entity\SavedAnalysisView;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisViewVisibility;
use App\User\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SavedAnalysisView>
 */
final class SavedAnalysisViewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SavedAnalysisView::class);
    }

    public function save(SavedAnalysisView $savedView): void
    {
        $this->getEntityManager()->persist($savedView);
        $this->getEntityManager()->flush();
    }

    public function remove(SavedAnalysisView $savedView): void
    {
        $this->getEntityManager()->remove($savedView);
        $this->getEntityManager()->flush();
    }

    public function findForOwner(int $id, ?User $user): ?SavedAnalysisView
    {
        if (!$user instanceof User) {
            return null;
        }

        $saved = $this->find($id);
        if (!$saved instanceof SavedAnalysisView) {
            return null;
        }

        if ($saved->getOwner()->getId() !== $user->getId()) {
            return null;
        }

        if (AnalysisViewVisibility::Private !== $saved->getVisibility()) {
            return null;
        }

        return $saved;
    }

    /**
     * @return list<SavedAnalysisView>
     */
    public function findForOwnerOrdered(User $user): array
    {
        /** @var list<SavedAnalysisView> $items */
        $items = $this->createQueryBuilder('s')
            ->andWhere('s.owner = :owner')
            ->setParameter('owner', $user)
            ->orderBy('s.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $items;
    }
}
