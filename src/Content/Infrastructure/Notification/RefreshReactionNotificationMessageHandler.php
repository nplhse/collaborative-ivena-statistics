<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Notification;

use App\Content\Application\Contract\ReactionTargetProviderInterface;
use App\Content\Application\Message\RefreshReactionNotificationMessage;
use App\Content\Application\Reaction\ReactionNotificationTarget;
use App\Content\Application\Reaction\ReactionTargetRegistry;
use App\Content\Domain\Enum\ReactionType;
use App\Notification\Application\Contract\GroupedNotificationWriterInterface;
use App\Notification\Application\Publishing\NotificationAction;
use App\Notification\Application\Publishing\NotificationRequest;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Recomputes the grouped reaction notification of a target from the reaction table (#973), so that retries, changed
 * and removed reactions need no bookkeeping. The row lock on the state serialises concurrent jobs of one target.
 *
 * @psalm-suppress UnusedClass Registered as a message handler.
 */
#[AsMessageHandler]
final readonly class RefreshReactionNotificationMessageHandler
{
    private const int NAMED_ACTORS = 2;

    public function __construct(
        private Connection $connection,
        private ReactionTargetRegistry $targets,
        private UserRepository $users,
        private GroupedNotificationWriterInterface $writer,
    ) {
    }

    public function __invoke(RefreshReactionNotificationMessage $message): void
    {
        $this->connection->transactional(function () use ($message): void {
            $this->refresh($message);
        });
    }

    private function refresh(RefreshReactionNotificationMessage $message): void
    {
        $key = ['type' => $message->targetType->value, 'id' => $message->targetId];

        $this->connection->executeStatement(
            'INSERT INTO reaction_notification_state (target_type, target_id, notification_id, floor_reaction_id, through_reaction_id)
             VALUES (:type, :id, NULL, 0, 0) ON CONFLICT DO NOTHING',
            $key,
        );
        $state = $this->connection->fetchAssociative(
            'SELECT notification_id, floor_reaction_id, through_reaction_id FROM reaction_notification_state
             WHERE target_type = :type AND target_id = :id FOR UPDATE',
            $key,
        );
        if (false === $state) {
            return;
        }

        $notificationId = null === $state['notification_id'] ? null : (int) $state['notification_id'];
        $floor = (int) $state['floor_reaction_id'];
        $through = (int) $state['through_reaction_id'];
        $newest = max($through, (int) $this->connection->fetchOne(
            'SELECT COALESCE(MAX(id), 0) FROM reaction WHERE target_type = :type AND target_id = :id',
            $key,
        ));

        $target = $this->notifiableTarget($message);
        if (!$target instanceof ReactionNotificationTarget || null === $target->ownerId) {
            if (null !== $notificationId) {
                $this->writer->withdraw($notificationId);
            }
            $this->save($key, null, $newest, $newest);

            return;
        }

        $ownerId = $target->ownerId;
        if (!$this->writer->isEnabledFor(ReactionReceivedNotification::TYPE, $ownerId)) {
            // Reactions during this time are not reported later, when the user switches the notification on again.
            $this->save($key, null, $newest, $newest);

            return;
        }

        if (null !== $notificationId) {
            $group = $this->group($key, $floor, $ownerId);
            if (0 === $group['count']) {
                if ($this->writer->withdraw($notificationId)) {
                    $this->save($key, null, $newest, $newest);

                    return;
                }
            } else {
                $moveTo = $group['maxId'] > $through ? $group['newestAt'] : null;
                if ($this->writer->replace($notificationId, $this->parameters($message, $target, $group), $moveTo)) {
                    $this->save($key, $notificationId, $floor, $newest);

                    return;
                }
            }
        }

        // No open entry: everything handled so far belongs to earlier groups.
        $group = $this->group($key, $through, $ownerId);
        $createdId = 0 === $group['count'] ? null : $this->writer->create(new NotificationRequest(
            type: ReactionReceivedNotification::TYPE,
            recipientUserIds: [$ownerId],
            deduplicationKey: \sprintf('%s:%s:%d', ReactionReceivedNotification::TYPE, $key['type'], $key['id']),
            parameters: $this->parameters($message, $target, $group),
            action: new NotificationAction('app_reaction_open', ['targetType' => $key['type'], 'targetId' => $key['id']]),
            metadata: ['reference' => $key['type'].':'.$key['id']],
        ));

        null === $createdId
            ? $this->save($key, null, $newest, $newest)
            : $this->save($key, $createdId, $through, $newest);
    }

    /** The target, if its owner exists, is enabled and can still see it. */
    private function notifiableTarget(RefreshReactionNotificationMessage $message): ?ReactionNotificationTarget
    {
        $provider = $this->targets->get($message->targetType);
        $target = $provider?->notificationTarget($message->targetId);
        if (!$provider instanceof ReactionTargetProviderInterface || !$target instanceof ReactionNotificationTarget || null === $target->ownerId) {
            return null;
        }

        $owner = $this->users->find($target->ownerId);
        if (!$owner instanceof User || !$owner->isEnabled() || [] === $provider->visibleIds([$message->targetId], $owner)) {
            return null;
        }

        return $target;
    }

    /**
     * Reactions above the boundary, without the owner's own and without disabled users.
     *
     * @param array{type: string, id: int} $key
     *
     * @return array{count: int, maxId: int, newestAt: ?\DateTimeImmutable, byType: array<string, int>, actors: list<array{id: int, name: string}>}
     */
    private function group(array $key, int $floor, int $ownerId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT r.type, COUNT(*) AS reactions, MAX(r.id) AS max_id, MAX(r.created_at) AS newest_at
             FROM reaction r INNER JOIN "user" u ON u.id = r.user_id
             WHERE r.target_type = :type AND r.target_id = :id AND r.id > :floor AND r.user_id <> :owner AND u.is_enabled = TRUE
             GROUP BY r.type',
            [...$key, 'floor' => $floor, 'owner' => $ownerId],
        );

        $count = 0;
        $maxId = 0;
        $newestAt = null;
        $byType = [];
        foreach ($rows as $row) {
            $reactions = (int) $row['reactions'];
            $byType[(string) $row['type']] = $reactions;
            $count += $reactions;
            $maxId = max($maxId, (int) $row['max_id']);
            $at = new \DateTimeImmutable((string) $row['newest_at']);
            $newestAt = !$newestAt instanceof \DateTimeImmutable || $at > $newestAt ? $at : $newestAt;
        }

        return [
            'count' => $count,
            'maxId' => $maxId,
            'newestAt' => $newestAt,
            'byType' => $byType,
            'actors' => 0 === $count ? [] : $this->latestActors($key, $floor, $ownerId),
        ];
    }

    /**
     * The people the entry names: the two latest reactions of the group.
     *
     * @param array{type: string, id: int} $key
     *
     * @return list<array{id: int, name: string}>
     */
    private function latestActors(array $key, int $floor, int $ownerId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT u.id, COALESCE(NULLIF(TRIM(u.display_name), \'\'), u.username) AS name
             FROM reaction r INNER JOIN "user" u ON u.id = r.user_id
             WHERE r.target_type = :type AND r.target_id = :id AND r.id > :floor AND r.user_id <> :owner AND u.is_enabled = TRUE
             ORDER BY r.id DESC LIMIT '.self::NAMED_ACTORS,
            [...$key, 'floor' => $floor, 'owner' => $ownerId],
        );

        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name']], $rows);
    }

    /**
     * @param array{count: int, maxId: int, newestAt: ?\DateTimeImmutable, byType: array<string, int>, actors: list<array{id: int, name: string}>} $group
     *
     * @return array<string, scalar|null>
     */
    private function parameters(RefreshReactionNotificationMessage $message, ReactionNotificationTarget $target, array $group): array
    {
        $breakdown = [];
        foreach (ReactionType::cases() as $type) {
            if (isset($group['byType'][$type->value])) {
                $breakdown[] = $type->emoji().' '.$group['byType'][$type->value];
            }
        }

        return [
            'targetType' => $message->targetType->value,
            'title' => $target->title ?? '',
            'count' => $group['count'],
            'breakdown' => implode(' ', $breakdown),
            'actors' => match (true) {
                1 === $group['count'] => 'one',
                2 === $group['count'] => 'two',
                default => 'many',
            },
            'firstActor' => $group['actors'][0]['name'] ?? null,
            'secondActor' => $group['actors'][1]['name'] ?? null,
            'others' => max(0, $group['count'] - self::NAMED_ACTORS),
            'actorIds' => ','.implode(',', array_column($group['actors'], 'id')).',',
        ];
    }

    /**
     * @param array{type: string, id: int} $key
     */
    private function save(array $key, ?int $notificationId, int $floor, int $through): void
    {
        $this->connection->executeStatement(
            'UPDATE reaction_notification_state
             SET notification_id = :notification, floor_reaction_id = :floor, through_reaction_id = :through
             WHERE target_type = :type AND target_id = :id',
            [...$key, 'notification' => $notificationId, 'floor' => $floor, 'through' => $through],
        );
    }
}
