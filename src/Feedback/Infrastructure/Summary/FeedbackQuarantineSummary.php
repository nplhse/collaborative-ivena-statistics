<?php

declare(strict_types=1);

namespace App\Feedback\Infrastructure\Summary;

use App\Feedback\Application\Contract\FeedbackQuarantineSummaryInterface;
use App\Feedback\Domain\Enum\FeedbackClassification;
use App\Feedback\Infrastructure\Notification\AdminFeedbackQuarantineSummaryNotification;
use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Notification\Application\Publishing\NotificationAction;
use App\Notification\Application\Publishing\NotificationRequest;
use App\User\Application\Contract\AdminNotificationRecipientResolverInterface;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Reports automatically quarantined feedback that no summary covered yet (`quarantine_reported_at` null).
 *
 * Selecting the entries (locked), publishing the notification and marking the entries happen in one
 * transaction; the publisher joins it as a savepoint. A failed run therefore reports nothing and marks
 * nothing, and the next run picks the same entries up; a finished run never reports them again. The
 * deduplication key names exactly the reported entries. Without new entries nothing is published.
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for FeedbackQuarantineSummaryInterface.
 */
#[AsAlias(FeedbackQuarantineSummaryInterface::class)]
final readonly class FeedbackQuarantineSummary implements FeedbackQuarantineSummaryInterface
{
    public const string QUARANTINE_ROUTE = 'app_admin_dashboard_feedback_quarantine_index';

    public function __construct(
        private Connection $connection,
        private AdminNotificationRecipientResolverInterface $recipients,
        private NotificationPublisherInterface $publisher,
        private ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function countPending(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM feedback WHERE classification = :quarantined AND quarantine_reported_at IS NULL',
            ['quarantined' => FeedbackClassification::QUARANTINED->value],
        );
    }

    #[\Override]
    public function send(): int
    {
        return $this->connection->transactional(function (Connection $connection): int {
            /** @var list<int|string> $rawIds */
            $rawIds = $connection->fetchFirstColumn(
                'SELECT id FROM feedback WHERE classification = :quarantined AND quarantine_reported_at IS NULL ORDER BY id FOR UPDATE',
                ['quarantined' => FeedbackClassification::QUARANTINED->value],
            );
            $ids = array_map(intval(...), $rawIds);
            if ([] === $ids) {
                return 0;
            }

            $total = (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM feedback WHERE classification = :quarantined',
                ['quarantined' => FeedbackClassification::QUARANTINED->value],
            );

            $recipientIds = $this->recipients->recipientIds();
            if ([] !== $recipientIds) {
                $this->publisher->publish(new NotificationRequest(
                    type: AdminFeedbackQuarantineSummaryNotification::TYPE,
                    recipientUserIds: $recipientIds,
                    deduplicationKey: \sprintf('%s:%s', AdminFeedbackQuarantineSummaryNotification::TYPE, sha1(implode(',', $ids))),
                    parameters: ['count' => \count($ids), 'total' => $total],
                    action: new NotificationAction(self::QUARANTINE_ROUTE),
                    metadata: ['reference' => \sprintf('feedback:%d', max($ids))],
                ));
            }

            $connection->executeStatement(
                \sprintf('UPDATE feedback SET quarantine_reported_at = ? WHERE id IN (%s)', implode(', ', array_fill(0, \count($ids), '?'))),
                [$this->clock->now()->format('Y-m-d H:i:s'), ...$ids],
            );

            return \count($ids);
        });
    }
}
