<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Repository;

use App\Statistics\Domain\Entity\SavedExplorerView;
use App\User\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SavedExplorerView>
 */
final class SavedExplorerViewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SavedExplorerView::class);
    }

    public function save(SavedExplorerView $view): void
    {
        $this->getEntityManager()->persist($view);
        $this->getEntityManager()->flush();
    }

    public function remove(SavedExplorerView $view): void
    {
        $this->getEntityManager()->remove($view);
        $this->getEntityManager()->flush();
    }

    public function findBySlug(string $slug): ?SavedExplorerView
    {
        $view = $this->findOneBy(['slug' => $slug]);

        return $view instanceof SavedExplorerView ? $view : null;
    }

    public function findByCreator(int $id, User $user): ?SavedExplorerView
    {
        $view = $this->createQueryBuilder('v')
            ->andWhere('v.id = :id')
            ->andWhere('v.isSystem = :isSystem')
            ->andWhere('IDENTITY(v.createdBy) = :userId')
            ->setParameter('id', $id)
            ->setParameter('isSystem', false)
            ->setParameter('userId', $user->getId(), Types::INTEGER)
            ->getQuery()
            ->getOneOrNullResult();

        return $view instanceof SavedExplorerView ? $view : null;
    }

    /**
     * @return list<SavedExplorerView>
     */
    public function findByCreatorOrdered(User $user): array
    {
        /** @var list<SavedExplorerView> $items */
        $items = $this->createQueryBuilder('v')
            ->andWhere('v.isSystem = :isSystem')
            ->andWhere('IDENTITY(v.createdBy) = :userId')
            ->setParameter('isSystem', false)
            ->setParameter('userId', $user->getId(), Types::INTEGER)
            ->orderBy('v.updatedAt', 'DESC')
            ->addOrderBy('v.title', 'ASC')
            ->getQuery()
            ->getResult();

        return $items;
    }

    /**
     * @return list<SavedExplorerView>
     */
    public function findAllSystemViewsOrdered(): array
    {
        /** @var list<SavedExplorerView> $items */
        $items = $this->createQueryBuilder('v')
            ->andWhere('v.isSystem = :isSystem')
            ->setParameter('isSystem', true)
            ->orderBy('v.category', 'ASC')
            ->addOrderBy('v.title', 'ASC')
            ->getQuery()
            ->getResult();

        return $items;
    }
}
