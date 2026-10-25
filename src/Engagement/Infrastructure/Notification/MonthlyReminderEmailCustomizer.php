<?php

declare(strict_types=1);

namespace App\Engagement\Infrastructure\Notification;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Engagement\Application\MonthlyReminderContentBuilder;
use App\Notification\Application\Contract\NotificationEmailCustomizerInterface;
use App\Notification\Application\Delivery\NotificationEmailContent;
use App\Shared\Infrastructure\Mail\MailConfig;
use App\User\Domain\Entity\User;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Renders the reminder email with the existing monthly reminder design. The content is
 * built when the email is delivered, for the reference date stored with the notification, so the
 * entry itself only carries ids and names. The dispatch id header keeps `monthly_reminder_dispatch`
 * up to date (MonthlyReminderDeliverySubscriber).
 *
 * @psalm-suppress UnusedClass Tagged via NotificationEmailCustomizerInterface.
 */
final readonly class MonthlyReminderEmailCustomizer implements NotificationEmailCustomizerInterface
{
    public const string DISPATCH_ID_HEADER = 'X-COIS-Monthly-Reminder-Dispatch-Id';

    public const string ATTEMPT_ID_HEADER = 'X-COIS-Monthly-Reminder-Attempt-Id';

    public function __construct(
        private HospitalRepository $hospitalRepository,
        private MonthlyReminderContentBuilder $contentBuilder,
        private TranslatorInterface $translator,
        private MailConfig $mailConfig,
    ) {
    }

    #[\Override]
    public function supports(string $type): bool
    {
        return MonthlyReminderNotification::TYPE === $type;
    }

    #[\Override]
    public function customize(NotificationEmailContent $content, User $recipient): void
    {
        $hospital = $this->hospitalRepository->find((int) ($content->parameters['hospitalId'] ?? 0));
        if (!$hospital instanceof Hospital) {
            // The hospital is gone: keep the generic email with the stored title and text.
            return;
        }

        $storedDate = $content->parameters['referenceDate'] ?? null;
        $referenceDate = \is_string($storedDate) ? new \DateTimeImmutable($storedDate, new \DateTimeZone('Europe/Berlin')) : null;
        $reminder = $this->contentBuilder->build($hospital, $referenceDate, $content->locale);

        $content->subject = $this->translator->trans('monthly_reminder.subject', [
            'hospital' => $reminder->hospitalName,
            'period' => $reminder->reportingPeriodLabel,
        ], 'engagement', $content->locale);
        $content->htmlTemplate = '@Engagement/email/monthly_submission_reminder.html.twig';
        $content->context = ['content' => $reminder, 'app_title' => $this->mailConfig->appName];

        $dispatchId = $content->parameters['dispatchId'] ?? null;
        if (\is_int($dispatchId)) {
            $content->headers[self::DISPATCH_ID_HEADER] = (string) $dispatchId;

            // The send attempt, so a late mail event of an earlier attempt cannot change a newer one.
            $attemptId = $content->parameters['attemptId'] ?? null;
            if (\is_string($attemptId) && '' !== $attemptId) {
                $content->headers[self::ATTEMPT_ID_HEADER] = $attemptId;
            }
        }
    }
}
