<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Reaction;

use App\Content\Application\Contract\ReactionTargetProviderInterface;
use App\Content\Application\Reaction\ReactionNotificationTarget;
use App\Content\Domain\Enum\ReactionTargetType;
use App\User\Domain\Entity\User;
use App\User\Domain\Enum\UserActivityType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;

/**
 * Activity timeline entries: signed-in users only, as the timeline; activities of disabled users and announcements of
 * posts not yet published are hidden, as in every feed (ProjectActivityQuery).
 *
 * @psalm-suppress UnusedClass Tagged via ReactionTargetProviderInterface.
 */
final readonly class ActivityReactionTarget implements ReactionTargetProviderInterface
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
        return ReactionTargetType::Activity;
    }

    #[\Override]
    public function visibleIds(array $ids, ?User $viewer): array
    {
        if ([] === $ids || !$viewer instanceof User) {
            return [];
        }

        return array_map(intval(...), $this->connection->fetchFirstColumn(
            'SELECT a.id FROM user_activity a INNER JOIN "user" u ON u.id = a.user_id
             WHERE a.id IN (:ids) AND u.is_enabled = TRUE AND NOT (a.type = :postPublished AND a.occurred_at > :now)',
            ['ids' => $ids, 'postPublished' => UserActivityType::POST_PUBLISHED->value, 'now' => $this->clock->now()],
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
            'SELECT id FROM user_activity WHERE id IN (:ids)',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        ));
    }

    #[\Override]
    public function notificationTarget(int $id): ?ReactionNotificationTarget
    {
        $row = $this->connection->fetchAssociative('SELECT user_id, metadata FROM user_activity WHERE id = :id', ['id' => $id]);
        if (false === $row) {
            return null;
        }

        $metadata = json_decode((string) $row['metadata'], true);
        $metadata = \is_array($metadata) ? $metadata : [];
        $ownerId = (int) $row['user_id'];
        $slug = $metadata['slug'] ?? $metadata['postSlug'] ?? null;

        if (\is_string($slug) && '' !== $slug) {
            $commentId = $metadata['commentId'] ?? null;

            return new ReactionNotificationTarget(
                ownerId: $ownerId,
                title: null,
                route: 'app_blog_show',
                routeParameters: ['slug' => $slug],
                fragment: \is_int($commentId) ? 'reactions-comment-'.$commentId : null,
            );
        }

        $hospitalPublicId = $metadata['hospitalPublicId'] ?? null;
        if (\is_string($hospitalPublicId) && '' !== $hospitalPublicId) {
            return new ReactionNotificationTarget($ownerId, null, 'app_explore_hospital_show', ['publicId' => $hospitalPublicId]);
        }

        return new ReactionNotificationTarget($ownerId, null, 'app_activity_timeline');
    }
}
