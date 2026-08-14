<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Adapter;

use App\Content\Application\Blog\CommentExcerpt;
use App\Content\Domain\Entity\Post;
use App\Content\Domain\Entity\PostComment;
use App\Content\Domain\Enum\PostStatus;
use App\User\Application\Activity\UserActivityBackfillCommentRecord;
use App\User\Application\Activity\UserActivityBackfillPostRecord;
use App\User\Application\Contract\UserActivityBackfillContentSourceInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** @psalm-suppress UnusedClass Wired via #[AsAlias] for UserActivityBackfillContentSourceInterface. */
#[AsAlias(UserActivityBackfillContentSourceInterface::class)]
final readonly class DoctrineUserActivityBackfillContentSource implements UserActivityBackfillContentSourceInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[\Override]
    public function publishedPosts(): array
    {
        /** @var list<array{
         *     userId: int|string|null,
         *     postId: int|string,
         *     title: string,
         *     slug: string,
         *     publishedAt: \DateTimeImmutable|string|null
         * }> $rows
         */
        $rows = $this->entityManager->createQueryBuilder()
            ->select(
                'IDENTITY(p.createdBy) AS userId',
                'p.id AS postId',
                'p.title AS title',
                'p.slug AS slug',
                'p.publishedAt AS publishedAt',
            )
            ->from(Post::class, 'p')
            ->andWhere('p.status = :status')
            ->andWhere('p.publishedAt <= :now')
            ->andWhere('IDENTITY(p.createdBy) IS NOT NULL')
            ->setParameter('status', PostStatus::PUBLISHED)
            ->setParameter('now', new \DateTimeImmutable('now'), Types::DATETIME_IMMUTABLE)
            ->orderBy('p.publishedAt', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $records = [];
        foreach ($rows as $row) {
            $userId = null !== $row['userId'] ? (int) $row['userId'] : 0;
            $publishedAt = $row['publishedAt'];
            if (!$publishedAt instanceof \DateTimeImmutable || $userId < 1 || '' === $row['title'] || '' === $row['slug']) {
                continue;
            }

            $records[] = new UserActivityBackfillPostRecord(
                userId: $userId,
                postId: (int) $row['postId'],
                title: $row['title'],
                slug: $row['slug'],
                publishedAt: $publishedAt,
            );
        }

        return $records;
    }

    #[\Override]
    public function commentsOnPublishedPosts(): array
    {
        /** @var list<array{
         *     userId: int|string|null,
         *     commentId: int|string,
         *     postTitle: string,
         *     postSlug: string,
         *     content: string,
         *     createdAt: \DateTimeImmutable|string
         * }> $rows
         */
        $rows = $this->entityManager->createQueryBuilder()
            ->select(
                'IDENTITY(c.author) AS userId',
                'c.id AS commentId',
                'p.title AS postTitle',
                'p.slug AS postSlug',
                'c.content AS content',
                'c.createdAt AS createdAt',
            )
            ->from(PostComment::class, 'c')
            ->innerJoin('c.post', 'p')
            ->andWhere('IDENTITY(c.author) IS NOT NULL')
            ->andWhere('p.status = :status')
            ->andWhere('p.publishedAt <= :now')
            ->setParameter('status', PostStatus::PUBLISHED)
            ->setParameter('now', new \DateTimeImmutable('now'), Types::DATETIME_IMMUTABLE)
            ->orderBy('c.createdAt', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $records = [];
        foreach ($rows as $row) {
            $userId = null !== $row['userId'] ? (int) $row['userId'] : 0;
            $createdAt = $row['createdAt'];
            if (!$createdAt instanceof \DateTimeImmutable || $userId < 1) {
                continue;
            }

            $records[] = new UserActivityBackfillCommentRecord(
                userId: $userId,
                commentId: (int) $row['commentId'],
                postTitle: $row['postTitle'],
                postSlug: $row['postSlug'],
                excerpt: CommentExcerpt::from($row['content']),
                createdAt: $createdAt,
            );
        }

        return $records;
    }
}
