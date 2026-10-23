<?php

declare(strict_types=1);

namespace App\Engagement\Infrastructure\EventSubscriber;

use App\Engagement\Infrastructure\Notification\MonthlyReminderEmailCustomizer;
use App\Engagement\Infrastructure\Repository\MonthlyReminderDispatchRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/**
 * Marks a reminder dispatch as sent when the mail transport accepted its email, but only while the
 * dispatch is still queued for the send attempt the email belongs to (headers set by
 * MonthlyReminderEmailCustomizer). A late success of an earlier attempt never changes a newer one.
 *
 * Failures are not handled here: a reminder email that fails for good reaches Engagement as
 * NotificationDeliveryFailed (MonthlyReminderDeliveryFailureSubscriber), with the same attempt check.
 *
 * @psalm-suppress UnusedClass Registered via #[AsEventListener].
 */
final readonly class MonthlyReminderDeliverySubscriber
{
    private const string TIMEZONE = 'Europe/Berlin';

    public function __construct(
        private MonthlyReminderDispatchRepository $dispatchRepository,
    ) {
    }

    #[AsEventListener(event: SentMessageEvent::class)]
    public function onSent(SentMessageEvent $event): void
    {
        $message = $event->getMessage()->getOriginalMessage();
        $dispatchId = $this->header($message, MonthlyReminderEmailCustomizer::DISPATCH_ID_HEADER);
        if (null === $dispatchId || !ctype_digit($dispatchId)) {
            return;
        }

        $this->dispatchRepository->markAttemptSent(
            (int) $dispatchId,
            $this->header($message, MonthlyReminderEmailCustomizer::ATTEMPT_ID_HEADER),
            new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)),
        );
    }

    private function header(RawMessage $message, string $name): ?string
    {
        if (!$message instanceof Message) {
            return null;
        }

        $value = trim((string) $message->getHeaders()->get($name)?->getBodyAsString());

        return '' === $value ? null : $value;
    }
}
