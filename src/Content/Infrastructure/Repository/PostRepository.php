<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Repository;

use App\Content\Domain\Entity\Post;
use App\Content\Domain\Enum\PostStatus;
use App\Content\UI\Http\DTO\BlogListQueryParametersDTO;
use App\Shared\Infrastructure\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Post>
 */
final class PostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Post::class);
    }

    public function getPublishedPaginator(BlogListQueryParametersDTO $query): Paginator
    {
        $qb = $this->createQueryBuilder('p')
            ->addSelect('c', 't')
            ->leftJoin('p.category', 'c')
            ->leftJoin('p.tags', 't')
            ->andWhere('p.status = :status')
            ->andWhere('p.publishedAt <= :now')
            ->setParameter('status', PostStatus::PUBLISHED)
            ->setParameter('now', new \DateTimeImmutable('now'))
            ->orderBy('p.publishedAt', 'DESC');

        return new Paginator($qb)->paginate($query->page, $query->limit);
    }

    public function getPublishedByCategorySlugPaginator(string $categorySlug, BlogListQueryParametersDTO $query): Paginator
    {
        $qb = $this->createQueryBuilder('p')
            ->addSelect('c', 't')
            ->leftJoin('p.category', 'c')
            ->leftJoin('p.tags', 't')
            ->andWhere('c.slug = :slug')
            ->andWhere('p.status = :status')
            ->andWhere('p.publishedAt <= :now')
            ->setParameter('slug', $categorySlug)
            ->setParameter('status', PostStatus::PUBLISHED)
            ->setParameter('now', new \DateTimeImmutable('now'))
            ->orderBy('p.publishedAt', 'DESC');

        return new Paginator($qb)->paginate($query->page, $query->limit);
    }

    public function getPublishedByTagSlugPaginator(string $tagSlug, BlogListQueryParametersDTO $query): Paginator
    {
        $qb = $this->createQueryBuilder('p')
            ->addSelect('c', 't')
            ->leftJoin('p.category', 'c')
            ->leftJoin('p.tags', 't')
            ->andWhere('t.slug = :slug')
            ->andWhere('p.status = :status')
            ->andWhere('p.publishedAt <= :now')
            ->setParameter('slug', $tagSlug)
            ->setParameter('status', PostStatus::PUBLISHED)
            ->setParameter('now', new \DateTimeImmutable('now'))
            ->orderBy('p.publishedAt', 'DESC');

        return new Paginator($qb)->paginate($query->page, $query->limit);
    }

    /**
     * @return list<Post>
     */
    public function findPublishedForIndex(?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->addSelect('c', 't')
            ->leftJoin('p.category', 'c')
            ->leftJoin('p.tags', 't')
            ->andWhere('p.status = :status')
            ->andWhere('p.publishedAt <= :now')
            ->setParameter('status', PostStatus::PUBLISHED)
            ->setParameter('now', new \DateTimeImmutable('now'))
            ->orderBy('p.publishedAt', 'DESC');

        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        /** @var list<Post> $posts */
        $posts = $qb->getQuery()->getResult();

        return $posts;
    }

    public function findPublishedBySlug(string $slug): ?Post
    {
        /** @var ?Post $post */
        $post = $this->createQueryBuilder('p')
            ->addSelect('c', 't', 'comments', 'children', 'commentAuthor')
            ->leftJoin('p.category', 'c')
            ->leftJoin('p.tags', 't')
            ->leftJoin('p.comments', 'comments')
            ->leftJoin('comments.children', 'children')
            ->leftJoin('comments.author', 'commentAuthor')
            ->andWhere('p.slug = :slug')
            ->andWhere('p.status = :status')
            ->andWhere('p.publishedAt <= :now')
            ->setParameter('slug', $slug)
            ->setParameter('status', PostStatus::PUBLISHED)
            ->setParameter('now', new \DateTimeImmutable('now'))
            ->orderBy('comments.createdAt', 'ASC')
            ->addOrderBy('children.createdAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $post;
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.slug = :slug')
            ->setParameter('slug', $slug);

        if (null !== $excludeId) {
            $qb->andWhere('p.id != :excludeId')->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function findPreviousPublishedPost(Post $currentPost): ?Post
    {
        $publishedAt = $currentPost->getPublishedAt();
        $currentId = $currentPost->getId();

        if (!$publishedAt instanceof \DateTimeImmutable || null === $currentId) {
            return null;
        }

        /** @var ?Post $post */
        $post = $this->createQueryBuilder('p')
            ->andWhere('p.status = :status')
            ->andWhere('p.publishedAt <= :now')
            ->andWhere('(p.publishedAt < :publishedAt OR (p.publishedAt = :publishedAt AND p.id < :id))')
            ->setParameter('status', PostStatus::PUBLISHED)
            ->setParameter('now', new \DateTimeImmutable('now'))
            ->setParameter('publishedAt', $publishedAt)
            ->setParameter('id', $currentId)
            ->orderBy('p.publishedAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $post;
    }

    public function findNextPublishedPost(Post $currentPost): ?Post
    {
        $publishedAt = $currentPost->getPublishedAt();
        $currentId = $currentPost->getId();

        if (!$publishedAt instanceof \DateTimeImmutable || null === $currentId) {
            return null;
        }

        /** @var ?Post $post */
        $post = $this->createQueryBuilder('p')
            ->andWhere('p.status = :status')
            ->andWhere('p.publishedAt <= :now')
            ->andWhere('(p.publishedAt > :publishedAt OR (p.publishedAt = :publishedAt AND p.id > :id))')
            ->setParameter('status', PostStatus::PUBLISHED)
            ->setParameter('now', new \DateTimeImmutable('now'))
            ->setParameter('publishedAt', $publishedAt)
            ->setParameter('id', $currentId)
            ->orderBy('p.publishedAt', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $post;
    }
}
