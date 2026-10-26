<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Monitoring;

use App\Shared\Infrastructure\Monitoring\Messenger\MessengerMetricRecorder;
use App\Shared\Infrastructure\Monitoring\Messenger\MessengerMetricSubscriber;
use App\Storage\Application\Message\MeasureStorageMessage;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class MessengerMetricSubscriberTest extends KernelTestCase
{
    private const string TRANSPORT = 'async_priority_low';

    public function testAHandledMessageIsCountedWithItsDurationAndRetryState(): void
    {
        $clock = new MockClock('2026-10-26 08:15:00', 'UTC');
        $subscriber = $this->subscriber($clock);

        $first = new Envelope(new MeasureStorageMessage());
        $subscriber->onReceived(new WorkerMessageReceivedEvent($first, self::TRANSPORT));
        $clock->sleep(0.25);
        $subscriber->onHandled(new WorkerMessageHandledEvent($first, self::TRANSPORT));

        $retried = new Envelope(new MeasureStorageMessage(), [new RedeliveryStamp(1)]);
        $subscriber->onReceived(new WorkerMessageReceivedEvent($retried, self::TRANSPORT));
        $clock->sleep(1.5);
        $subscriber->onHandled(new WorkerMessageHandledEvent($retried, self::TRANSPORT));

        self::assertSame(
            ['message_class' => MeasureStorageMessage::class, 'transport' => self::TRANSPORT, 'attempts' => 2, 'handled' => 2, 'handled_after_retry' => 1, 'failed' => 0, 'duration_ms_sum' => 1750, 'duration_ms_max' => 1500],
            $this->metric(),
        );
    }

    public function testAnAttemptWithoutAReceivedEventCountsWithoutDuration(): void
    {
        $clock = new MockClock('2026-10-26 08:15:00', 'UTC');
        $subscriber = $this->subscriber($clock);
        $envelope = new Envelope(new MeasureStorageMessage());

        $subscriber->onReceived(new WorkerMessageReceivedEvent($envelope, self::TRANSPORT));
        $subscriber->onHandled(new WorkerMessageHandledEvent($envelope, self::TRANSPORT));
        $clock->sleep(2);
        // The start belongs to the attempt above; a second handled event has none.
        $subscriber->onHandled(new WorkerMessageHandledEvent($envelope, self::TRANSPORT));

        $metric = $this->metric();
        self::assertSame(2, $metric['attempts']);
        self::assertSame(0, $metric['duration_ms_sum']);
    }

    public function testAFailureRecordsTheHandlerExceptionAndTheAttemptNumber(): void
    {
        $clock = new MockClock('2026-10-26 08:15:00', 'UTC');
        $subscriber = $this->subscriber($clock);
        $envelope = new Envelope(new MeasureStorageMessage(), [new RedeliveryStamp(2)]);
        $failure = new HandlerFailedException($envelope, [new \DomainException('Klinikum Beispiel')]);

        $subscriber->onReceived(new WorkerMessageReceivedEvent($envelope, self::TRANSPORT));
        $clock->sleep(0.1);
        $subscriber->onFailed(new WorkerMessageFailedEvent($envelope, self::TRANSPORT, $failure));

        $row = $this->connection()->fetchAssociative('SELECT attempt, will_retry, error_class FROM messenger_failure');
        self::assertIsArray($row);
        self::assertSame(3, (int) $row['attempt']);
        self::assertFalse((bool) $row['will_retry']);
        self::assertSame(\DomainException::class, $row['error_class']);

        $metric = $this->metric();
        self::assertSame(1, $metric['failed']);
        self::assertSame(100, $metric['duration_ms_sum']);
    }

    public function testTheContainerCountsAFailureAsRetriedWhileMessengerRetriesIt(): void
    {
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $envelope = new Envelope(new MeasureStorageMessage());

        $dispatcher->dispatch(new WorkerMessageReceivedEvent($envelope, self::TRANSPORT));
        $dispatcher->dispatch(new WorkerMessageFailedEvent($envelope, self::TRANSPORT, new \RuntimeException('temporary')));

        $metric = $this->metric();
        self::assertSame(1, $metric['attempts']);
        self::assertSame(0, $metric['failed']);
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT retried FROM messenger_metric'));
    }

    public function testTheContainerCountsAFailureAsFailedOnceTheRetriesAreUsedUp(): void
    {
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        // async_priority_low allows three retries.
        $envelope = new Envelope(new MeasureStorageMessage(), [new RedeliveryStamp(3)]);

        $dispatcher->dispatch(new WorkerMessageReceivedEvent($envelope, self::TRANSPORT));
        $dispatcher->dispatch(new WorkerMessageFailedEvent($envelope, self::TRANSPORT, new \RuntimeException('permanent')));

        $metric = $this->metric();
        self::assertSame(1, $metric['failed']);
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT retried FROM messenger_metric'));
        self::assertSame(4, (int) $this->connection()->fetchOne('SELECT attempt FROM messenger_failure'));
    }

    /**
     * @return array{message_class: string, transport: string, attempts: int, handled: int, handled_after_retry: int, failed: int, duration_ms_sum: int, duration_ms_max: int}
     */
    private function metric(): array
    {
        $rows = $this->connection()->fetchAllAssociative('SELECT * FROM messenger_metric');
        self::assertCount(1, $rows);
        $row = $rows[0];

        return [
            'message_class' => (string) $row['message_class'],
            'transport' => (string) $row['transport'],
            'attempts' => (int) $row['attempts'],
            'handled' => (int) $row['handled'],
            'handled_after_retry' => (int) $row['handled_after_retry'],
            'failed' => (int) $row['failed'],
            'duration_ms_sum' => (int) $row['duration_ms_sum'],
            'duration_ms_max' => (int) $row['duration_ms_max'],
        ];
    }

    private function subscriber(MockClock $clock): MessengerMetricSubscriber
    {
        return new MessengerMetricSubscriber(new MessengerMetricRecorder($this->connection(), $clock, new NullLogger()), $clock);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
