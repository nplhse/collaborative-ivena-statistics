<?php

declare(strict_types=1);

namespace App\Feedback\Infrastructure\EventSubscriber;

use App\Feedback\Application\Event\FeedbackReceived;
use App\Feedback\Domain\Entity\Feedback;
use App\Feedback\Infrastructure\Notification\AdminFeedbackReceivedNotification;
use App\Feedback\Infrastructure\Repository\FeedbackRepository;
use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Notification\Application\Publishing\NotificationAction;
use App\Notification\Application\Publishing\NotificationRequest;
use App\User\Application\Contract\AdminNotificationRecipientResolverInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Tells the feedback recipients about new feedback (`admin.feedback_received`), once per entry. Only the
 * category and the sender's username are stored, never the message; the email adds a short preview.
 */
#[AsEventListener(event: FeedbackReceived::class, method: 'onFeedbackReceived')]
final readonly class FeedbackReceivedNotificationSubscriber
{
    public function __construct(
        private FeedbackRepository $feedbackRepository,
        private AdminNotificationRecipientResolverInterface $recipients,
        private NotificationPublisherInterface $publisher,
    ) {
    }

    public function onFeedbackReceived(FeedbackReceived $event): void
    {
        $feedback = $this->feedbackRepository->find($event->feedbackId);
        if (!$feedback instanceof Feedback) {
            return;
        }

        $recipientIds = $this->recipients->feedbackRecipientIds();
        if ([] === $recipientIds) {
            return;
        }

        $username = $feedback->getSubmittedBy()?->getUserIdentifier();
        $this->publisher->publish(new NotificationRequest(
            type: AdminFeedbackReceivedNotification::TYPE,
            recipientUserIds: $recipientIds,
            deduplicationKey: \sprintf('%s:%d', AdminFeedbackReceivedNotification::TYPE, $event->feedbackId),
            parameters: [
                'feedbackId' => $event->feedbackId,
                'category' => $feedback->getCategory()->value ?? 'other',
                'sender' => null === $username ? 'guest' : 'user',
                'username' => $username ?? '',
            ],
            action: new NotificationAction('app_admin_dashboard_feedback_detail', ['entityId' => $event->feedbackId]),
            metadata: ['reference' => \sprintf('feedback:%d', $event->feedbackId)],
        ));
    }
}
