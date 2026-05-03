<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Repository;

use App\Content\Domain\Entity\Page;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Page>
 */
final class PageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Page::class);
    }

    public function findPublishedByPath(string $path): ?Page
    {
        /** @var ?Page $page */
        $page = $this->createQueryBuilder('p')
            ->andWhere('p.path = :path')
            ->andWhere('p.status = :status')
            ->setParameter('path', $path)
            ->setParameter('status', Page::STATUS_PUBLISHED)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $page;
    }

    /**
     * @return list<Page>
     */
    public function findChildrenSorted(Page $parent): array
    {
        /** @var list<Page> $pages */
        $pages = $this->createQueryBuilder('p')
            ->andWhere('p.parent = :parent')
            ->setParameter('parent', $parent)
            ->orderBy('p.sortOrder', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $pages;
    }

    /**
     * Published pages visible to authenticated users (public + authenticated visibility).
     *
     * @return list<Page>
     */
    public function findAllPublishedVisibleToAuthenticatedUser(): array
    {
        /** @var list<Page> $pages */
        $pages = $this->createQueryBuilder('p')
            ->addSelect('parent')
            ->leftJoin('p.parent', 'parent')
            ->andWhere('p.status = :status')
            ->andWhere('p.visibility IN (:visibilities)')
            ->setParameter('status', Page::STATUS_PUBLISHED)
            ->setParameter('visibilities', [Page::VISIBILITY_PUBLIC, Page::VISIBILITY_AUTHENTICATED])
            ->getQuery()
            ->getResult();

        return $pages;
    }
}
