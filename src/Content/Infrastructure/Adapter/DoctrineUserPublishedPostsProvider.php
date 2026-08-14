<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Adapter;

use App\Content\Domain\Entity\Post;
use App\Content\Domain\Enum\PostStatus;
use App\User\Application\Contract\UserPublishedPostsProviderInterface;
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
    public function countPublishedByUserId(int $userId): int
    {
        if ($userId < 1) {
            return 0;
        }

        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Post::class, 'p')
            ->andWhere('IDENTITY(p.createdBy) = :userId')
            ->andWhere('p.status = :status')
            ->andWhere('p.publishedAt <= :now')
            ->setParameter('userId', $userId)
            ->setParameter('status', PostStatus::PUBLISHED)
            ->setParameter('now', new \DateTimeImmutable('now'), Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }
}
