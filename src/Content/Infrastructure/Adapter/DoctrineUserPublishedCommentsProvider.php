<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Adapter;

use App\Content\Domain\Entity\PostComment;
use App\Content\Domain\Enum\PostStatus;
use App\User\Application\Contract\UserPublishedCommentsProviderInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** @psalm-suppress UnusedClass Wired via #[AsAlias] for UserPublishedCommentsProviderInterface. */
#[AsAlias(UserPublishedCommentsProviderInterface::class)]
final readonly class DoctrineUserPublishedCommentsProvider implements UserPublishedCommentsProviderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[\Override]
    public function countOnPublishedPostsByUserId(int $userId): int
    {
        if ($userId < 1) {
            return 0;
        }

        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(PostComment::class, 'c')
            ->innerJoin('c.post', 'p')
            ->andWhere('IDENTITY(c.author) = :userId')
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
