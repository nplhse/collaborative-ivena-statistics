<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Reaction;

use App\Content\Application\Contract\ReactionTargetProviderInterface;
use App\Content\Domain\Enum\PostStatus;
use App\Content\Domain\Enum\ReactionTargetType;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;

/**
 * Blog comments: visible while their post is published, as on the blog.
 *
 * @psalm-suppress UnusedClass Tagged via ReactionTargetProviderInterface.
 */
final readonly class CommentReactionTarget implements ReactionTargetProviderInterface
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
        return ReactionTargetType::Comment;
    }

    #[\Override]
    public function visibleIds(array $ids, ?User $viewer): array
    {
        if ([] === $ids) {
            return [];
        }

        return array_map(intval(...), $this->connection->fetchFirstColumn(
            'SELECT c.id FROM post_comment c INNER JOIN post p ON p.id = c.post_id
             WHERE c.id IN (:ids) AND p.status = :status AND p.published_at <= :now',
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
            'SELECT id FROM post_comment WHERE id IN (:ids)',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        ));
    }
}
