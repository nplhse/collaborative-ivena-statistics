<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Repository;

use App\Content\Domain\Entity\PostTag;
use App\Content\Domain\Enum\PostStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PostTag>
 */
final class PostTagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PostTag::class);
    }

    public function findOneBySlug(string $slug): ?PostTag
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * @return list<array{tag: PostTag, postCount: int}>
     */
    public function findSidebarItems(): array
    {
        /** @var list<array{tag: PostTag, postCount: int}> $items */
        $items = $this->createQueryBuilder('t')
            ->select('t AS tag', 'COUNT(p.id) AS postCount')
            ->leftJoin('t.posts', 'p', 'WITH', 'p.status = :status AND p.publishedAt <= :now')
            ->setParameter('status', PostStatus::PUBLISHED)
            ->setParameter('now', new \DateTimeImmutable('now'))
            ->groupBy('t.id')
            ->having('COUNT(p.id) > 0')
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $items;
    }
}
