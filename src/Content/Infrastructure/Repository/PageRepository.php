<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Repository;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Domain\Enum\PageKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
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

    public function findOneWithPublishedTranslationByKey(PageKey $key): ?Page
    {
        /** @var ?Page $page */
        $page = $this->createQueryBuilder('p')
            ->addSelect('t')
            ->innerJoin('p.translations', 't')
            ->andWhere('p.key = :key')
            ->andWhere('t.status = :status')
            ->setParameter('key', $key->value, Types::STRING)
            ->setParameter('status', PageTranslation::STATUS_PUBLISHED)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $page;
    }

    /**
     * @return list<Page>
     */
    public function findAllWithPublishedTranslationAndKey(): array
    {
        /** @var list<Page> $pages */
        $pages = $this->createQueryBuilder('p')
            ->addSelect('t')
            ->innerJoin('p.translations', 't')
            ->andWhere('p.key IS NOT NULL')
            ->andWhere('t.status = :status')
            ->setParameter('status', PageTranslation::STATUS_PUBLISHED)
            ->getQuery()
            ->getResult();

        return $pages;
    }

    /**
     * @return list<Page>
     */
    public function findChildrenSorted(Page $parent): array
    {
        /** @var list<Page> $pages */
        $pages = $this->createQueryBuilder('p')
            ->andWhere('IDENTITY(p.parent) = :parentId')
            ->setParameter('parentId', $parent->getId(), Types::INTEGER)
            ->orderBy('p.sortOrder', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $pages;
    }

    /**
     * Pages that have at least one published translation.
     *
     * @return list<Page>
     */
    public function findAllWithPublishedTranslation(): array
    {
        /** @var list<Page> $pages */
        $pages = $this->createQueryBuilder('p')
            ->addSelect('parent')
            ->addSelect('t')
            ->leftJoin('p.parent', 'parent')
            ->innerJoin('p.translations', 't')
            ->andWhere('t.status = :status')
            ->setParameter('status', PageTranslation::STATUS_PUBLISHED)
            ->getQuery()
            ->getResult();

        return $pages;
    }

    /**
     * Pages with a published translation visible to authenticated users.
     *
     * @return list<Page>
     */
    public function findAllWithPublishedTranslationVisibleToAuthenticatedUser(): array
    {
        /** @var list<Page> $pages */
        $pages = $this->createQueryBuilder('p')
            ->addSelect('parent')
            ->addSelect('t')
            ->leftJoin('p.parent', 'parent')
            ->innerJoin('p.translations', 't')
            ->andWhere('t.status = :status')
            ->andWhere('p.visibility IN (:visibilities)')
            ->setParameter('status', PageTranslation::STATUS_PUBLISHED)
            ->setParameter('visibilities', [Page::VISIBILITY_PUBLIC, Page::VISIBILITY_AUTHENTICATED])
            ->getQuery()
            ->getResult();

        return $pages;
    }

    /**
     * Pages with a published translation and public visibility.
     *
     * @return list<Page>
     */
    public function findAllWithPublishedTranslationPublic(): array
    {
        /** @var list<Page> $pages */
        $pages = $this->createQueryBuilder('p')
            ->addSelect('parent')
            ->addSelect('t')
            ->leftJoin('p.parent', 'parent')
            ->innerJoin('p.translations', 't')
            ->andWhere('t.status = :status')
            ->andWhere('p.visibility = :visibility')
            ->setParameter('status', PageTranslation::STATUS_PUBLISHED)
            ->setParameter('visibility', Page::VISIBILITY_PUBLIC)
            ->getQuery()
            ->getResult();

        return $pages;
    }

    /**
     * @return list<Page>
     */
    public function findAllOrderedById(): array
    {
        /** @var list<Page> $pages */
        $pages = $this->createQueryBuilder('p')
            ->addSelect('t')
            ->leftJoin('p.translations', 't')
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $pages;
    }
}
