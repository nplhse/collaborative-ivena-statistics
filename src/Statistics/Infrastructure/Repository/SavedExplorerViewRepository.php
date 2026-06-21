<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Repository;

use App\Statistics\Domain\Entity\SavedExplorerView;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
