<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Notification;

use App\Import\Domain\Entity\UserImportMilestone;
use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Notification\Application\Publishing\NotificationAction;
use App\Notification\Application\Publishing\NotificationRequest;

/**
 * Publishes a recorded import milestone to its user. FirstImport is the only milestone type so far.
 * The deduplication key is derived from the milestone, so the event subscriber and the
 * reconciliation publish it at most once.
 */
final readonly class ImportMilestoneNotifier
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private NotificationPublisherInterface $publisher,
    ) {
    }

    public function notify(UserImportMilestone $milestone): void
    {
        $milestoneId = $milestone->getId();
        $userId = $milestone->getUser()->getId();
        if (null === $milestoneId || null === $userId) {
            return;
        }

        $import = $milestone->getImportRun()?->getImport();
        $importId = $import?->getId();

        $this->publisher->publish(new NotificationRequest(
            type: 'user.first_import',
            recipientUserIds: [$userId],
            deduplicationKey: \sprintf('user.first_import:%d', $milestoneId),
            parameters: ['importName' => $import?->getName() ?? ''],
            action: null === $importId
                ? new NotificationAction('app_import_index')
                : new NotificationAction('app_import_show', ['id' => $importId]),
            metadata: ['reference' => \sprintf('user_import_milestone:%d', $milestoneId)],
        ));
    }
}
