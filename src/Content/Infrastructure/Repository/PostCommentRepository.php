<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Repository;

use App\Content\Domain\Entity\Post;
use App\Content\Domain\Entity\PostComment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PostComment>
 */
final class PostCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PostComment::class);
    }

    /**
     * @return list<PostComment>
     */
    public function findRootCommentsForPost(Post $post): array
    {
        /** @var list<PostComment> $comments */
        $comments = $this->createQueryBuilder('c')
            ->addSelect('children')
            ->leftJoin('c.children', 'children')
            ->andWhere('IDENTITY(c.post) = :postId')
            ->andWhere('c.parent IS NULL')
            ->setParameter('postId', $post->getId(), Types::INTEGER)
            ->orderBy('c.createdAt', \SortDirection::Ascending)
            ->addOrderBy('children.createdAt', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $comments;
    }

    /** All comments of a post, replies included, as the blog list counts them (#1456). */
    public function countForPost(Post $post): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('IDENTITY(c.post) = :postId')
            ->setParameter('postId', $post->getId(), Types::INTEGER)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
