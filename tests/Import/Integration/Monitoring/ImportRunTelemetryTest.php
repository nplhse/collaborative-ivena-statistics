<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Monitoring;

use App\Import\Application\Contracts\ImportRunTelemetryInterface;
use App\Import\Application\DTO\ImportRunContext;
use App\Import\Application\DTO\ImportSummary;
use App\Import\Application\Message\ImportAllocationsMessage;
use App\Import\Application\Service\ImportRunLifecycle;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\EventSubscriber\ImportRunRetriesExhaustedSubscriber;
use App\Import\Infrastructure\Monitoring\ImportRunTelemetry;
use App\Import\Infrastructure\Monitoring\TimedRejectWriter;
use App\Import\Infrastructure\Monitoring\TimedRowReader;
use App\Shared\Infrastructure\Monitoring\JobRun\JobRunRecorder;
use App\Shared\Infrastructure\Monitoring\JobRun\JobRunThresholdConfig;
use App\Tests\Import\Doubles\Service\Adapter\InMemoryRejectWriter;
use App\Tests\Import\Doubles\Service\Adapter\InMemoryRowReader;
use App\Tests\Import\Integration\MessageHandler\ImportAllocationsMessageHandlerTestCase;
use Doctrine\DBAL\Connection;
use Sentry\State\HubInterface;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

final class ImportRunTelemetryTest extends ImportAllocationsMessageHandlerTestCase
{
    /** @var list<BufferingLogger> */
    private array $loggers = [];

    #[\Override]
    protected function tearDown(): void
    {
        // A BufferingLogger prints the logs nobody read when it is destroyed.
        foreach ($this->loggers as $logger) {
            $logger->cleanLogs();
        }
        $this->loggers = [];
        parent::tearDown();
    }

    public function testARetriedRunGetsTheNextAttemptAndTheAttemptsThreshold(): void
    {
        [$telemetry, $logger] = $this->telemetry();
        [$context, $import] = $this->startedRun();

        $telemetry->begin($context, $import);
        $telemetry->end(ImportRunTelemetryInterface::STATUS_RETRYING, new \RuntimeException('deadlock on hospital Klinikum'));
        $telemetry->begin($context, $import);
        $telemetry->end('completed');

        $rows = $this->connection()->fetchAllAssociative(
            'SELECT status, attempt, thresholds_exceeded, error_class FROM job_run WHERE import_run_id = ? ORDER BY attempt',
            [$context->runId->toRfc4122()],
        );
        self::assertSame([
            ['status' => 'retrying', 'attempt' => 1, 'thresholds_exceeded' => null, 'error_class' => \RuntimeException::class],
            ['status' => 'completed', 'attempt' => 2, 'thresholds_exceeded' => 'attempts', 'error_class' => null],
        ], $rows);

        $logs = $logger->cleanLogs();
        $summaries = $this->contextsOf($logs, 'import.run.summary');
        self::assertCount(2, $summaries);
        self::assertSame('retrying', $summaries[0]['status']);
        self::assertSame(\RuntimeException::class, $summaries[0]['error_class']);
        self::assertSame('allocation_import', $summaries[1]['job']);
        self::assertSame('console', $summaries[1]['trigger']);
        self::assertSame($context->importId, $summaries[1]['import_id']);
        self::assertArrayNotHasKey('msg', $summaries[0], 'Error messages stay local.');
        self::assertCount(1, $this->contextsOf($logs, 'import.run.threshold_exceeded'));
    }

    public function testTheLastRetryingAttemptFailsWhenMessengerGivesUp(): void
    {
        [$telemetry] = $this->telemetry();
        [$context, $import] = $this->startedRun();
        $message = new ImportAllocationsMessage($context->importId, $context->runId->toRfc4122());
        $telemetry->begin($context, $import);
        $telemetry->end(ImportRunTelemetryInterface::STATUS_RETRYING, new \RuntimeException('deadlock'));

        self::getContainer()->get(ImportRunRetriesExhaustedSubscriber::class)
            ->onMessageFailed(new WorkerMessageFailedEvent(new Envelope($message), 'async_priority_high', new \RuntimeException('deadlock')));

        self::assertSame('failed', $this->connection()->fetchOne('SELECT status FROM import_run WHERE id = ?', [$context->runId->toRfc4122()]));
        self::assertSame(
            [['status' => 'failed', 'attempt' => 1, 'error_class' => \RuntimeException::class]],
            $this->connection()->fetchAllAssociative('SELECT status, attempt, error_class FROM job_run WHERE import_run_id = ?', [$context->runId->toRfc4122()]),
        );

        // A second notification for the same run changes nothing.
        [$again, $logger] = $this->telemetry();
        $again->retriesExhausted($context->importId, $context->runId->toRfc4122(), ImportType::ALLOCATION);
        self::assertSame([], $this->contextsOf($logger->cleanLogs(), 'import.run.retries_exhausted'));
    }

    public function testRetriesExhaustedLogsTheFailedAttempt(): void
    {
        [$telemetry, $logger] = $this->telemetry();
        [$context, $import] = $this->startedRun();
        $telemetry->begin($context, $import);
        $telemetry->end(ImportRunTelemetryInterface::STATUS_RETRYING);
        $telemetry->begin($context, $import);
        $telemetry->end(ImportRunTelemetryInterface::STATUS_RETRYING);
        $logger->cleanLogs();

        $telemetry->retriesExhausted($context->importId, $context->runId->toRfc4122(), ImportType::ALLOCATION);

        $logs = $this->contextsOf($logger->cleanLogs(), 'import.run.retries_exhausted');
        self::assertCount(1, $logs);
        self::assertSame(2, $logs[0]['attempt']);
        self::assertSame('allocation_import', $logs[0]['job']);
        self::assertSame($context->importId, $logs[0]['import_id']);
        self::assertSame(
            ['retrying', 'failed'],
            $this->connection()->fetchFirstColumn('SELECT status FROM job_run WHERE import_run_id = ? ORDER BY attempt', [$context->runId->toRfc4122()]),
            'Only the last attempt fails; the earlier one was retried.',
        );
    }

    public function testARunningAttemptOfADeadWorkerIsAbandoned(): void
    {
        [$telemetry] = $this->telemetry();
        [$context, $import] = $this->startedRun();

        $telemetry->begin($context, $import);
        // The worker dies: no end(). Messenger redelivers the message.
        [$retry] = $this->telemetry();
        $retry->begin($context, $import);
        $retry->end('completed');

        self::assertSame(
            ['abandoned', 'completed'],
            $this->connection()->fetchFirstColumn('SELECT status FROM job_run WHERE import_run_id = ? ORDER BY attempt', [$context->runId->toRfc4122()]),
        );
    }

    public function testThePhasesOfTheRowLoopAreRecorded(): void
    {
        [$telemetry] = $this->telemetry();
        [$context, $import] = $this->startedRun();
        $telemetry->begin($context, $import);

        $reader = $telemetry->timedReader(InMemoryRowReader::fromAssocRows([['a' => '1'], ['a' => '2']]));
        $writer = $telemetry->timedRejectWriter(new InMemoryRejectWriter());
        self::assertInstanceOf(TimedRowReader::class, $reader);
        self::assertInstanceOf(TimedRejectWriter::class, $writer);

        $telemetry->startRows();
        self::assertSame([['a' => '1'], ['a' => '2']], iterator_to_array($reader->rowsAssoc(), false));
        $telemetry->addPersistTime(3_000_000, true);
        $telemetry->addPersistTime(2_000_000, false);
        $telemetry->rowsImported(new ImportSummary(2, 2, 0));
        $telemetry->end('completed');

        $metrics = json_decode((string) $this->connection()->fetchOne('SELECT metrics FROM job_run WHERE import_run_id = ?', [$context->runId->toRfc4122()]), true);
        self::assertIsArray($metrics);
        self::assertSame(1, $metrics['chunks']);
        self::assertSame(5, $metrics['persist_ms']);
        self::assertIsInt($metrics['read_ms']);
        self::assertIsInt($metrics['map_ms']);
    }

    public function testWithoutABegunRunNothingIsRecordedOrWrapped(): void
    {
        [$telemetry, $logger] = $this->telemetry();
        $reader = InMemoryRowReader::fromAssocRows([]);

        self::assertSame($reader, $telemetry->timedReader($reader));
        $telemetry->startRows();
        $telemetry->addPersistTime(1_000, true);
        $telemetry->end('completed');

        self::assertSame(0, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM job_run WHERE job IN ('allocation_import', 'closure_import')"));
        self::assertSame([], $this->contextsOf($logger->cleanLogs(), 'import.run.summary'));
    }

    /**
     * @return array{ImportRunTelemetry, BufferingLogger}
     */
    private function telemetry(): array
    {
        $logger = new BufferingLogger();
        $this->loggers[] = $logger;
        $container = self::getContainer();

        return [new ImportRunTelemetry(
            $container->get(JobRunRecorder::class),
            // No duration or memory limits: the test process memory (coverage, ParaTest) must not decide the result.
            new JobRunThresholdConfig([]),
            $container->get(HubInterface::class),
            $this->connection(),
            $logger,
        ), $logger];
    }

    /**
     * @return array{ImportRunContext, Import}
     */
    private function startedRun(): array
    {
        ['id' => $id] = $this->arrangeSingleRowSuccessfulCsvImport();
        $lifecycle = self::getContainer()->get(ImportRunLifecycle::class);
        $run = $lifecycle->register($id, ImportRunTrigger::Console);
        $context = $lifecycle->start(new ImportAllocationsMessage($id, $run->getId()->toRfc4122()));
        self::assertInstanceOf(ImportRunContext::class, $context);
        $import = $this->imports->find($id);
        self::assertInstanceOf(Import::class, $import);

        return [$context, $import];
    }

    /**
     * @param array<array{mixed, string, array<array-key, mixed>}> $logs
     *
     * @return list<array<array-key, mixed>>
     */
    private function contextsOf(array $logs, string $message): array
    {
        $contexts = [];
        foreach ($logs as [, $logged, $context]) {
            if ($logged === $message) {
                $contexts[] = $context;
            }
        }

        return $contexts;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
