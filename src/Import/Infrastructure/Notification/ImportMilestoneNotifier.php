<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Notification;

use App\Import\Domain\Entity\Import;
use App\Import\Domain\Entity\UserImportMilestone;
use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Notification\Application\Publishing\NotificationAction;
use App\Notification\Application\Publishing\NotificationRequest;

/**
 * Publishes a recorded import milestone to its user, scoped to the import's hospital. FirstImport is the only milestone type so far.
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

        // The text names the import and links to it, so it is scoped to the import's hospital (redacted after
        // access is revoked). Without the run, import or hospital (e.g. the import was deleted) there is no
        // scope to check: the milestone stays recorded, but nothing is announced.
        $import = $milestone->getImportRun()?->getImport();
        $importId = $import?->getId();
        $hospitalId = $import?->getHospital()?->getId();
        if (!$import instanceof Import || null === $importId || null === $hospitalId) {
            return;
        }

        $this->publisher->publish(new NotificationRequest(
            type: 'user.first_import',
            recipientUserIds: [$userId],
            deduplicationKey: \sprintf('user.first_import:%d', $milestoneId),
            parameters: ['importName' => (string) $import->getName()],
            action: new NotificationAction('app_import_show', ['id' => $importId]),
            scopeReferenceId: $hospitalId,
            metadata: ['reference' => \sprintf('user_import_milestone:%d', $milestoneId)],
        ));
    }
}
