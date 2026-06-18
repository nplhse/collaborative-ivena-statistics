<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Repository;

use App\Content\Domain\Entity\PostCategory;
use App\Content\Domain\Enum\PostStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PostCategory>
 */
final class PostCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PostCategory::class);
    }

    public function findOneBySlug(string $slug): ?PostCategory
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * @return list<array{category: PostCategory, postCount: int}>
     */
    public function findSidebarItems(): array
    {
        /** @var list<array{category: PostCategory, postCount: int}> $items */
        $items = $this->createQueryBuilder('c')
            ->select('c AS category', 'COUNT(p.id) AS postCount')
            ->leftJoin('c.posts', 'p', 'WITH', 'p.status = :status AND p.publishedAt <= :now')
            ->setParameter('status', PostStatus::PUBLISHED)
            ->setParameter('now', new \DateTimeImmutable('now'), Types::DATETIME_IMMUTABLE)
            ->groupBy('c.id')
            ->having('COUNT(p.id) > 0')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $items;
    }
}
