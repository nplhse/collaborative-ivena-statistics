<?php

declare(strict_types=1);

namespace App\Feedback\Infrastructure\Notification;

use App\Feedback\Application\FeedbackMailPreview;
use App\Feedback\Domain\Entity\Feedback;
use App\Feedback\Infrastructure\Repository\FeedbackRepository;
use App\Notification\Application\Contract\NotificationEmailCustomizerInterface;
use App\Notification\Application\Delivery\NotificationEmailContent;
use App\User\Domain\Entity\User;

/**
 * The email of `admin.feedback_received` keeps the former feedback mail: category, sender, page, time and
 * a short, link-free preview of the message ({@see FeedbackMailPreview}). The full message, email
 * addresses, the full page URL, user agent and context stay in the admin area. The entry is loaded at delivery time, so
 * the message is never stored in the notification; if it is gone, the generic email is sent.
 *
 * @psalm-suppress UnusedClass Tagged via NotificationEmailCustomizerInterface.
 */
final readonly class AdminFeedbackEmailCustomizer implements NotificationEmailCustomizerInterface
{
    public function __construct(
        private FeedbackRepository $feedbackRepository,
    ) {
    }

    #[\Override]
    public function supports(string $type): bool
    {
        return AdminFeedbackReceivedNotification::TYPE === $type;
    }

    #[\Override]
    public function customize(NotificationEmailContent $content, User $recipient): void
    {
        $feedbackId = $content->parameters['feedbackId'] ?? null;
        $feedback = \is_int($feedbackId) ? $this->feedbackRepository->find($feedbackId) : null;
        if (!$feedback instanceof Feedback) {
            return;
        }

        $content->htmlTemplate = '@Feedback/email/admin_feedback_notification.html.twig';
        $content->context = [
            'title' => $content->context['title'] ?? $content->subject,
            'inboxUrl' => $content->context['inboxUrl'] ?? null,
            'adminUrl' => $content->context['actionUrl'] ?? null,
            'category' => $feedback->getCategory()->value ?? 'other',
            'username' => $feedback->getSubmittedBy()?->getUserIdentifier(),
            'pagePath' => $feedback->getPagePath(),
            'routeName' => $feedback->getRouteName(),
            'createdAt' => $feedback->getCreatedAt(),
            'appVersion' => $feedback->getAppVersion(),
            'messagePreview' => FeedbackMailPreview::fromMessage((string) $feedback->getMessage()),
        ];
    }
}
