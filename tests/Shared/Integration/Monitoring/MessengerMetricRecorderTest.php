<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Monitoring;

use App\Shared\Infrastructure\Monitoring\Messenger\MessengerMetricRecorder;
use Doctrine\DBAL\Connection;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class MessengerMetricRecorderTest extends KernelTestCase
{
    private const string MESSAGE = 'App\Example\Message\ExampleMessage';

    public function testHandledAttemptsAreCountedPerHourMessageAndTransport(): void
    {
        $clock = new MockClock('2026-10-26 08:15:00', 'UTC');
        $recorder = $this->recorder($clock);

        $recorder->recordHandled(self::MESSAGE, 'async_priority_low', 100, false);
        $clock->sleep(60);
        $recorder->recordHandled(self::MESSAGE, 'async_priority_low', 300, true);
        $recorder->recordHandled(self::MESSAGE, 'async_priority_high', 50, false);
        $clock->sleep(3600);
        $recorder->recordHandled(self::MESSAGE, 'async_priority_low', 70, false);

        self::assertSame([
            ['bucket' => '2026-10-26 08:00:00', 'transport' => 'async_priority_high', 'attempts' => 1, 'handled' => 1, 'handled_after_retry' => 0, 'retried' => 0, 'failed' => 0, 'duration_ms_sum' => 50, 'duration_ms_max' => 50, 'last_activity' => '2026-10-26 08:16:00'],
            ['bucket' => '2026-10-26 08:00:00', 'transport' => 'async_priority_low', 'attempts' => 2, 'handled' => 2, 'handled_after_retry' => 1, 'retried' => 0, 'failed' => 0, 'duration_ms_sum' => 400, 'duration_ms_max' => 300, 'last_activity' => '2026-10-26 08:16:00'],
            ['bucket' => '2026-10-26 09:00:00', 'transport' => 'async_priority_low', 'attempts' => 1, 'handled' => 1, 'handled_after_retry' => 0, 'retried' => 0, 'failed' => 0, 'duration_ms_sum' => 70, 'duration_ms_max' => 70, 'last_activity' => '2026-10-26 09:16:00'],
        ], $this->metrics());
    }

    public function testFailedAttemptsAreSplitIntoRetriedAndFailedAndKeepOneRowEach(): void
    {
        $recorder = $this->recorder(new MockClock('2026-10-26 08:15:00', 'UTC'));

        $recorder->recordFailed(self::MESSAGE, 'async_priority_low', 20, 1, true, \RuntimeException::class);
        $recorder->recordFailed(self::MESSAGE, 'async_priority_low', 40, 2, false, \LogicException::class);

        $metrics = $this->metrics();
        self::assertCount(1, $metrics);
        self::assertSame(2, $metrics[0]['attempts']);
        self::assertSame(0, $metrics[0]['handled']);
        self::assertSame(1, $metrics[0]['retried']);
        self::assertSame(1, $metrics[0]['failed']);
        self::assertSame(60, $metrics[0]['duration_ms_sum']);

        self::assertSame([
            ['message_class' => self::MESSAGE, 'transport' => 'async_priority_low', 'attempt' => 1, 'will_retry' => true, 'error_class' => \RuntimeException::class, 'occurred' => '2026-10-26 08:15:00'],
            ['message_class' => self::MESSAGE, 'transport' => 'async_priority_low', 'attempt' => 2, 'will_retry' => false, 'error_class' => \LogicException::class, 'occurred' => '2026-10-26 08:15:00'],
        ], array_map(static fn (array $row): array => [
            'message_class' => $row['message_class'],
            'transport' => $row['transport'],
            'attempt' => (int) $row['attempt'],
            'will_retry' => (bool) $row['will_retry'],
            'error_class' => $row['error_class'],
            'occurred' => $row['occurred'],
        ], $this->connection()->fetchAllAssociative(
            "SELECT message_class, transport, attempt, will_retry, error_class, to_char(occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS occurred FROM messenger_failure ORDER BY id",
        )));
    }

    public function testAFailedWriteIsLoggedAndDoesNotFailTheMessage(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, array<array-key, mixed>}> */
            public array $records = [];

            #[\Override]
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [(string) $message, $context];
            }
        };
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willThrowException(new \RuntimeException('connection lost'));
        $connection->method('transactional')->willThrowException(new \RuntimeException('connection lost'));
        $recorder = new MessengerMetricRecorder($connection, new MockClock(), $logger);

        $recorder->recordHandled(self::MESSAGE, 'async_priority_low', 10, false);
        $recorder->recordFailed(self::MESSAGE, 'async_priority_low', 10, 1, false, \RuntimeException::class);

        self::assertCount(2, $logger->records);
        self::assertSame('messenger_metric.record_failed', $logger->records[0][0]);
        self::assertSame(['message_class' => self::MESSAGE, 'transport' => 'async_priority_low', 'error_class' => \RuntimeException::class], $logger->records[0][1]);
        self::assertSame('messenger_metric.record_failed', $logger->records[1][0]);
    }

    /**
     * @return list<array{bucket: string, transport: string, attempts: int, handled: int, handled_after_retry: int, retried: int, failed: int, duration_ms_sum: int, duration_ms_max: int, last_activity: string}>
     */
    private function metrics(): array
    {
        $rows = $this->connection()->fetchAllAssociative(<<<'SQL'
SELECT to_char(bucket_start AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS bucket, transport, attempts, handled, handled_after_retry,
       retried, failed, duration_ms_sum, duration_ms_max, to_char(last_activity_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS last_activity
FROM messenger_metric
WHERE granularity = 'hour' AND message_class = :class
ORDER BY bucket_start, transport
SQL, ['class' => self::MESSAGE]);

        return array_map(static fn (array $row): array => [
            'bucket' => (string) $row['bucket'],
            'transport' => (string) $row['transport'],
            'attempts' => (int) $row['attempts'],
            'handled' => (int) $row['handled'],
            'handled_after_retry' => (int) $row['handled_after_retry'],
            'retried' => (int) $row['retried'],
            'failed' => (int) $row['failed'],
            'duration_ms_sum' => (int) $row['duration_ms_sum'],
            'duration_ms_max' => (int) $row['duration_ms_max'],
            'last_activity' => (string) $row['last_activity'],
        ], $rows);
    }

    private function recorder(MockClock $clock): MessengerMetricRecorder
    {
        return new MessengerMetricRecorder($this->connection(), $clock, new NullLogger());
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
