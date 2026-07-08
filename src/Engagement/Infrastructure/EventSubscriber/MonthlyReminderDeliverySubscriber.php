<?php

declare(strict_types=1);

namespace App\Engagement\Infrastructure\EventSubscriber;

use App\Engagement\Application\MonthlyReminderMailer;
use App\Engagement\Infrastructure\Repository\MonthlyReminderDispatchRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

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
        $dispatchId = $this->extractDispatchId($event->getMessage()->getOriginalMessage());
        if (null === $dispatchId) {
            return;
        }

        $dispatch = $this->dispatchRepository->find($dispatchId);
        if (null === $dispatch) {
            return;
        }

        $dispatch->markSent(new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)));
        $this->dispatchRepository->save($dispatch);
    }

    #[AsEventListener(event: WorkerMessageFailedEvent::class)]
    public function onMessengerFailed(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }

        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof SendEmailMessage) {
            return;
        }

        $dispatchId = $this->extractDispatchId($message->getMessage());
        if (null === $dispatchId) {
            return;
        }

        $dispatch = $this->dispatchRepository->find($dispatchId);
        if (null === $dispatch) {
            return;
        }

        $dispatch->markFailed($event->getThrowable()->getMessage());
        $this->dispatchRepository->save($dispatch);
    }

    private function extractDispatchId(RawMessage $message): ?int
    {
        if (!$message instanceof Message) {
            return null;
        }

        $header = $message->getHeaders()->get(MonthlyReminderMailer::DISPATCH_ID_HEADER);
        if (!$header instanceof \Symfony\Component\Mime\Header\HeaderInterface) {
            return null;
        }

        $value = trim($header->getBodyAsString());

        return '' !== $value && ctype_digit($value) ? (int) $value : null;
    }
}
