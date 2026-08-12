<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Adapter;

use App\Content\Domain\Entity\Post;
use App\Content\Domain\Enum\PostStatus;
use App\User\Application\Contract\UserPublishedPostsProviderInterface;
use App\User\Application\Explore\UserPublishedPostSummary;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** @psalm-suppress UnusedClass Wired via #[AsAlias] for UserPublishedPostsProviderInterface. */
#[AsAlias(UserPublishedPostsProviderInterface::class)]
final readonly class DoctrineUserPublishedPostsProvider implements UserPublishedPostsProviderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[\Override]
    public function findPublishedByUserId(int $userId, int $limit = 10): array
    {
        if ($userId < 1 || $limit < 1) {
            return [];
        }

        /** @var list<array{title: string, slug: string, publishedAt: \DateTimeImmutable}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('p.title AS title', 'p.slug AS slug', 'p.publishedAt AS publishedAt')
            ->from(Post::class, 'p')
            ->andWhere('IDENTITY(p.createdBy) = :userId')
            ->andWhere('p.status = :status')
            ->andWhere('p.publishedAt <= :now')
            ->setParameter('userId', $userId)
            ->setParameter('status', PostStatus::PUBLISHED)
            ->setParameter('now', new \DateTimeImmutable('now'), Types::DATETIME_IMMUTABLE)
            ->orderBy('p.publishedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        $posts = [];
        foreach ($rows as $row) {
            $publishedAt = $row['publishedAt'];
            if (!$publishedAt instanceof \DateTimeImmutable) {
                continue;
            }

            $posts[] = new UserPublishedPostSummary(
                title: $row['title'],
                slug: $row['slug'],
                publishedAt: $publishedAt,
            );
        }

        return $posts;
    }
}
