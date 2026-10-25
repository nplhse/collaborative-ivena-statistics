<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Monitoring;

use App\Shared\Infrastructure\Monitoring\JobRun\JobRunRecorder;
use App\Shared\Infrastructure\Monitoring\Sentry\SentryTracesSampler;
use Doctrine\DBAL\Connection;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Sentry\Tracing\SamplingContext;
use Sentry\Tracing\TransactionContext;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class JobRunRecorderTest extends KernelTestCase
{
    public function testARunIsStartedAndFinishedWithItsCountersAndError(): void
    {
        $recorder = $this->recorder(new MockClock('2026-10-25 16:00:00', 'UTC'));

        $recorder->start('run-1', 'test_job', 'console', ['hospital_ids' => [40]]);
        self::assertSame('running', $this->connection()->fetchOne("SELECT status FROM job_run WHERE id = 'run-1'"));

        $recorder->finish('run-1', 'test_job', 'failed', 1234, 5678, ['slow', 'memory'], ['hospital_ids' => [40], 'rows' => 12], new \RuntimeException('SQLSTATE: Klinikum Beispiel'));

        $run = $this->connection()->fetchAssociative("SELECT *, started_at AT TIME ZONE 'UTC' AS started_utc FROM job_run WHERE id = 'run-1'");
        self::assertIsArray($run);
        self::assertSame('test_job', $run['job']);
        self::assertSame('console', $run['triggered_by']);
        self::assertSame('failed', $run['status']);
        self::assertSame('2026-10-25 16:00:00', $run['started_utc']);
        self::assertSame(1234, (int) $run['duration_ms']);
        self::assertSame(5678, (int) $run['peak_memory_bytes']);
        self::assertSame('slow,memory', $run['thresholds_exceeded']);
        self::assertSame(\RuntimeException::class, $run['error_class']);
        self::assertSame('SQLSTATE: Klinikum Beispiel', $run['error_message']);
        self::assertEquals(['hospital_ids' => [40], 'rows' => 12], json_decode((string) $run['metrics'], true));
    }

    public function testOnlyRunningRowsOfTheSameJobAreAbandoned(): void
    {
        $recorder = $this->recorder(new MockClock());
        $recorder->start('dead', 'test_job', 'console');
        $recorder->start('other-job', 'other_job', 'console');
        $recorder->start('current', 'test_job', 'console');

        self::assertSame(1, $recorder->abandonRunning('test_job', 'current'));
        self::assertSame(
            ['current' => 'running', 'dead' => 'abandoned', 'other-job' => 'running'],
            $this->connection()->fetchAllKeyValue('SELECT id, status FROM job_run ORDER BY id'),
        );
    }

    public function testAFailedWriteIsLoggedAndDoesNotFailTheJob(): void
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
        $recorder = new JobRunRecorder($this->connection(), new MockClock(), $logger);

        $recorder->start('run-1', 'test_job', 'console');
        // The primary key is taken, so the second insert fails.
        $recorder->start('run-1', 'test_job', 'console');

        self::assertCount(1, $logger->records);
        self::assertSame('job_run.record_failed', $logger->records[0][0]);
        self::assertSame('test_job', $logger->records[0][1]['job']);
        self::assertSame('run-1', $logger->records[0][1]['run_id']);
    }

    public function testEveryJobIsSampledCompletelyInTheContainer(): void
    {
        self::bootKernel();
        $sampler = self::getContainer()->get('sentry.callback.traces_sampler');
        self::assertInstanceOf(SentryTracesSampler::class, $sampler);

        self::assertSame(1.0, $sampler(SamplingContext::getDefault(TransactionContext::make()->setOp('closure.rebuild'))));
        self::assertSame(1.0, $sampler(SamplingContext::getDefault(TransactionContext::make()->setOp('import.allocations'))));
        self::assertSame(1.0, $sampler(SamplingContext::getDefault(TransactionContext::make()->setOp('import.closures'))));
    }

    private function recorder(MockClock $clock): JobRunRecorder
    {
        return new JobRunRecorder($this->connection(), $clock, new NullLogger());
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
