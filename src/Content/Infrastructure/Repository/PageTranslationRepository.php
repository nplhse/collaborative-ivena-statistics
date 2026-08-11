<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Repository;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PageTranslation>
 */
final class PageTranslationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PageTranslation::class);
    }

    public function findPublishedByPath(string $path): ?PageTranslation
    {
        /** @var ?PageTranslation $translation */
        $translation = $this->createQueryBuilder('t')
            ->addSelect('p')
            ->innerJoin('t.page', 'p')
            ->andWhere('t.path = :path')
            ->andWhere('t.status = :status')
            ->setParameter('path', $path)
            ->setParameter('status', PageTranslation::STATUS_PUBLISHED)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $translation;
    }

    public function findOneByPageAndLocale(Page $page, string $locale): ?PageTranslation
    {
        $pageId = $page->getId();
        if (null === $pageId) {
            return null;
        }

        /** @var ?PageTranslation $translation */
        $translation = $this->createQueryBuilder('t')
            ->andWhere('IDENTITY(t.page) = :pageId')
            ->andWhere('t.locale = :locale')
            ->setParameter('pageId', $pageId, Types::INTEGER)
            ->setParameter('locale', $locale)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $translation;
    }

    public function findPublishedByPageAndLocale(Page $page, string $locale): ?PageTranslation
    {
        $pageId = $page->getId();
        if (null === $pageId) {
            return null;
        }

        /** @var ?PageTranslation $translation */
        $translation = $this->createQueryBuilder('t')
            ->andWhere('IDENTITY(t.page) = :pageId')
            ->andWhere('t.locale = :locale')
            ->andWhere('t.status = :status')
            ->setParameter('pageId', $pageId, Types::INTEGER)
            ->setParameter('locale', $locale)
            ->setParameter('status', PageTranslation::STATUS_PUBLISHED)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $translation;
    }

    /**
     * @return list<PageTranslation>
     */
    public function findAllPublishedPublic(): array
    {
        /** @var list<PageTranslation> $translations */
        $translations = $this->createQueryBuilder('t')
            ->addSelect('p')
            ->addSelect('parent')
            ->innerJoin('t.page', 'p')
            ->leftJoin('p.parent', 'parent')
            ->andWhere('t.status = :status')
            ->andWhere('p.visibility = :visibility')
            ->setParameter('status', PageTranslation::STATUS_PUBLISHED)
            ->setParameter('visibility', Page::VISIBILITY_PUBLIC)
            ->getQuery()
            ->getResult();

        return $translations;
    }

    public function countByLocale(string $locale): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.locale = :locale')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Sibling slug uniqueness within the same parent page and locale.
     */
    public function existsSiblingSlug(Page $page, string $locale, string $slug, ?int $excludeTranslationId = null): bool
    {
        $parent = $page->getParent();

        $qb = $this->createQueryBuilder('t')
            ->select('1')
            ->innerJoin('t.page', 'p')
            ->andWhere('t.locale = :locale')
            ->andWhere('t.slug = :slug')
            ->andWhere('p.id != :pageId')
            ->setParameter('locale', $locale)
            ->setParameter('slug', $slug)
            ->setParameter('pageId', $page->getId(), Types::INTEGER)
            ->setMaxResults(1);

        if ($parent instanceof Page) {
            $qb->andWhere('IDENTITY(p.parent) = :parentId')
                ->setParameter('parentId', $parent->getId(), Types::INTEGER);
        } else {
            $qb->andWhere('p.parent IS NULL');
        }

        if (null !== $excludeTranslationId) {
            $qb->andWhere('t.id != :excludeId')
                ->setParameter('excludeId', $excludeTranslationId, Types::INTEGER);
        }

        return null !== $qb->getQuery()->getOneOrNullResult();
    }
}
