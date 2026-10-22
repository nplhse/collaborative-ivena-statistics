<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Publishing;

use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Application\Definition\NotificationRegistry;
use App\Notification\Application\Preference\NotificationChannelResolver;
use App\Notification\Application\Preference\NotificationPreferenceOverrides;
use App\Notification\Application\Publishing\NotificationRequest;
use App\Notification\Domain\Enum\NotificationAccessScope;
use App\Notification\Domain\Enum\NotificationChannel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Stores notifications with plain DBAL statements (ADR 016, "Delivery semantics").
 *
 * Per recipient, one transaction claims the deduplication key, inserts the inbox entry and updates
 * the recipient state. Inside an open transaction of the caller it runs as a savepoint. DBAL is used
 * instead of the entity manager so that publishing never flushes pending changes of the caller.
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for NotificationPublisherInterface.
 */
#[AsAlias(NotificationPublisherInterface::class)]
final readonly class DbalNotificationPublisher implements NotificationPublisherInterface
{
    /**
     * How long a toast may be claimed by an active browser context.
     */
    public const string TOAST_WINDOW = 'PT5M';

    public function __construct(
        private Connection $connection,
        private NotificationRegistry $registry,
        private NotificationChannelResolver $channelResolver,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function publish(NotificationRequest $request): void
    {
        $definition = $this->registry->get($request->type);
        $this->assertValid($request, $definition);

        foreach (array_values(array_unique($request->recipientUserIds)) as $recipientId) {
            try {
                $this->publishForRecipient($request, $definition, $recipientId);
            } catch (\Throwable $exception) {
                $this->logger->error('notification.publish_failed', [
                    'type' => $request->type,
                    'recipient_id' => $recipientId,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function assertValid(NotificationRequest $request, NotificationDefinition $definition): void
    {
        if ('' === trim($request->deduplicationKey)) {
            throw new \InvalidArgumentException(sprintf('Notification "%s" needs a deduplication key.', $request->type));
        }

        if (NotificationAccessScope::Hospital === $definition->accessScope && null === $request->scopeReferenceId) {
            throw new \InvalidArgumentException(sprintf('Notification "%s" is hospital-scoped and needs a scope reference.', $request->type));
        }

        foreach ($request->recipientUserIds as $recipientId) {
            if ($recipientId < 1) {
                throw new \InvalidArgumentException(sprintf('Notification "%s" has an invalid recipient id %d.', $request->type, $recipientId));
            }
        }
    }

    private function publishForRecipient(NotificationRequest $request, NotificationDefinition $definition, int $recipientId): void
    {
        $channels = $this->channelResolver->resolve($definition, NotificationPreferenceOverrides::none());

        // Only in-app and toast are resolved until external channels get delivery jobs (step 6 of ADR 016);
        // NotificationDefinitionValidator keeps them out of default and mandatory channels.
        if (!$channels->has(NotificationChannel::InApp)) {
            return;
        }

        $now = $this->clock->now();

        $this->connection->transactional(function (Connection $connection) use ($request, $definition, $recipientId, $channels, $now): void {
            $deduplicationId = $this->claimDeduplicationKey($connection, $recipientId, $request->deduplicationKey, $now->add($definition->deduplicationInterval()), $now);
            if (null === $deduplicationId) {
                return;
            }

            $toastPendingUntil = $channels->has(NotificationChannel::Toast) ? $now->add(new \DateInterval(self::TOAST_WINDOW)) : null;
            $notificationId = $this->insertNotification($connection, $request, $definition, $recipientId, $now, $toastPendingUntil);

            $connection->executeStatement(
                'UPDATE notification_deduplication SET notification_id = :notification WHERE id = :id',
                ['notification' => $notificationId, 'id' => $deduplicationId],
            );

            $this->bumpRecipientState($connection, $recipientId, $now);
        });
    }

    /**
     * Claims a new key or takes over an expired one. Returns null while an unexpired holder exists.
     */
    private function claimDeduplicationKey(
        Connection $connection,
        int $recipientId,
        string $key,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $now,
    ): ?int {
        $id = $connection->fetchOne(
            <<<'SQL'
                INSERT INTO notification_deduplication (recipient_id, deduplication_key, expires_at, notification_id)
                VALUES (:recipient, :key, :expires_at, NULL)
                ON CONFLICT (recipient_id, deduplication_key) DO UPDATE
                    SET expires_at = EXCLUDED.expires_at, notification_id = NULL
                    WHERE notification_deduplication.expires_at < :now
                RETURNING id
                SQL,
            ['recipient' => $recipientId, 'key' => $key, 'expires_at' => $expiresAt, 'now' => $now],
            ['expires_at' => Types::DATETIME_IMMUTABLE, 'now' => Types::DATETIME_IMMUTABLE],
        );

        return false === $id ? null : (int) $id;
    }

    private function insertNotification(
        Connection $connection,
        NotificationRequest $request,
        NotificationDefinition $definition,
        int $recipientId,
        \DateTimeImmutable $now,
        ?\DateTimeImmutable $toastPendingUntil,
    ): int {
        return (int) $connection->fetchOne(
            <<<'SQL'
                INSERT INTO notification (
                    recipient_id, type, category, priority, translation_domain, title_key, message_key,
                    parameters, action_route, action_parameters, access_scope, scope_reference_id, required_role,
                    metadata, in_app_visible, created_at, toast_pending_until
                ) VALUES (
                    :recipient, :type, :category, :priority, :translation_domain, :title_key, :message_key,
                    :parameters, :action_route, :action_parameters, :access_scope, :scope_reference_id, :required_role,
                    :metadata, :in_app_visible, :created_at, :toast_pending_until
                )
                RETURNING id
                SQL,
            [
                'recipient' => $recipientId,
                'type' => $definition->type,
                'category' => $definition->category->value,
                'priority' => $definition->priority->value,
                'translation_domain' => $definition->translationDomain,
                'title_key' => $definition->titleKey(),
                'message_key' => $definition->messageKey(),
                'parameters' => $request->parameters,
                'action_route' => $request->action?->route,
                'action_parameters' => $request->action->routeParameters ?? [],
                'access_scope' => $definition->accessScope->value,
                'scope_reference_id' => $request->scopeReferenceId,
                'required_role' => $definition->requiredRole,
                'metadata' => $request->metadata,
                'in_app_visible' => true,
                'created_at' => $now,
                'toast_pending_until' => $toastPendingUntil,
            ],
            [
                'parameters' => Types::JSON,
                'action_parameters' => Types::JSON,
                'metadata' => Types::JSON,
                'in_app_visible' => Types::BOOLEAN,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'toast_pending_until' => Types::DATETIME_IMMUTABLE,
            ],
        );
    }

    private function bumpRecipientState(Connection $connection, int $recipientId, \DateTimeImmutable $now): void
    {
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO notification_recipient_state (user_id, version, unread_count, updated_at)
                VALUES (:user, 1, 1, :now)
                ON CONFLICT (user_id) DO UPDATE
                    SET version = notification_recipient_state.version + 1,
                        unread_count = notification_recipient_state.unread_count + 1,
                        updated_at = EXCLUDED.updated_at
                SQL,
            ['user' => $recipientId, 'now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }
}
