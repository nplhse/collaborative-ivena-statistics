<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\Messenger;

use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * Feeds MessengerMetricRecorder from the worker events (ADR 019): every message a worker handles counts as one
 * attempt, measured from "received" to "handled" or "failed". Messages handled synchronously are not counted.
 *
 * A worker handles one message at a time, so the start of the current attempt is kept in this service.
 * The failure listener runs at the default priority, after Symfony's retry listener (priority 100) decided whether
 * a retry follows.
 *
 * @psalm-suppress UnusedClass Registered via #[AsEventListener].
 */
final class MessengerMetricSubscriber
{
    private ?\DateTimeImmutable $receivedAt = null;

    public function __construct(
        private readonly MessengerMetricRecorder $recorder,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @psalm-suppress UnusedParam The event type registers the listener; the attempt starts now. */
    #[AsEventListener]
    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        $this->receivedAt = $this->clock->now();
    }

    #[AsEventListener]
    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        $envelope = $event->getEnvelope();

        $this->recorder->recordHandled(
            $envelope->getMessage()::class,
            $event->getReceiverName(),
            $this->elapsedMs(),
            RedeliveryStamp::getRetryCountFromEnvelope($envelope) > 0,
        );
    }

    #[AsEventListener]
    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        $envelope = $event->getEnvelope();

        $this->recorder->recordFailed(
            $envelope->getMessage()::class,
            $event->getReceiverName(),
            $this->elapsedMs(),
            RedeliveryStamp::getRetryCountFromEnvelope($envelope) + 1,
            $event->willRetry(),
            $this->errorClass($event->getThrowable()),
        );
    }

    private function elapsedMs(): int
    {
        $receivedAt = $this->receivedAt;
        $this->receivedAt = null;
        if (!$receivedAt instanceof \DateTimeImmutable) {
            return 0;
        }

        $now = $this->clock->now();
        $seconds = (float) $now->format('U.u') - (float) $receivedAt->format('U.u');

        return max(0, (int) round($seconds * 1000.0));
    }

    /**
     * The handler's exception rather than Messenger's wrapper, so recurring causes can be told apart.
     */
    private function errorClass(\Throwable $throwable): string
    {
        if ($throwable instanceof HandlerFailedException) {
            foreach ($throwable->getWrappedExceptions() as $wrapped) {
                return $wrapped::class;
            }
        }

        return $throwable::class;
    }
}
