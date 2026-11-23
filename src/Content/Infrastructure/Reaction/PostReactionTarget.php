<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Reaction;

use App\Content\Application\Contract\ReactionTargetProviderInterface;
use App\Content\Application\Reaction\ReactionNotificationTarget;
use App\Content\Domain\Enum\PostStatus;
use App\Content\Domain\Enum\ReactionTargetType;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;

/**
 * Blog posts: everyone sees published posts, as on the blog.
 *
 * @psalm-suppress UnusedClass Tagged via ReactionTargetProviderInterface.
 */
final readonly class PostReactionTarget implements ReactionTargetProviderInterface
{
    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function targetType(): ReactionTargetType
    {
        return ReactionTargetType::Post;
    }

    #[\Override]
    public function visibleIds(array $ids, ?User $viewer): array
    {
        if ([] === $ids) {
            return [];
        }

        return array_map(intval(...), $this->connection->fetchFirstColumn(
            'SELECT id FROM post WHERE id IN (:ids) AND status = :status AND published_at <= :now',
            ['ids' => $ids, 'status' => PostStatus::PUBLISHED->value, 'now' => $this->clock->now()],
            ['ids' => ArrayParameterType::INTEGER, 'now' => Types::DATETIME_IMMUTABLE],
        ));
    }

    #[\Override]
    public function existingIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return array_map(intval(...), $this->connection->fetchFirstColumn(
            'SELECT id FROM post WHERE id IN (:ids)',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        ));
    }

    #[\Override]
    public function notificationTarget(int $id): ?ReactionNotificationTarget
    {
        $row = $this->connection->fetchAssociative('SELECT created_by_id, title, slug FROM post WHERE id = :id', ['id' => $id]);
        if (false === $row) {
            return null;
        }

        return new ReactionNotificationTarget(
            ownerId: null === $row['created_by_id'] ? null : (int) $row['created_by_id'],
            title: (string) $row['title'],
            route: 'app_blog_show',
            routeParameters: ['slug' => (string) $row['slug']],
        );
    }
}
