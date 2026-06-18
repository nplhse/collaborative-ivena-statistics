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
            ->orderBy('c.createdAt', 'ASC')
            ->addOrderBy('children.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $comments;
    }
}
