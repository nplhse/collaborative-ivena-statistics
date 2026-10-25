<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Monitoring;

use App\Shared\Infrastructure\Monitoring\JobRun\JobRunRecorder;
use App\Shared\Infrastructure\Monitoring\JobRun\JobRunThresholdConfig;
use App\Statistics\Application\DTO\AllocationStatsProjectionRebuildResult;
use App\Statistics\Infrastructure\Monitoring\AllocationProjectionRunTelemetry;
use Doctrine\DBAL\Connection;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class AllocationProjectionRunTelemetryTest extends KernelTestCase
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

    public function testWithoutABegunRunTheWorkStillRunsAndNothingIsRecorded(): void
    {
        [$telemetry, $logger] = $this->telemetry([]);

        self::assertSame(42, $telemetry->measure('projection.insert', static fn (): int => 42));
        $telemetry->rebuilt(new AllocationStatsProjectionRebuildResult(1, 2));
        $telemetry->end();

        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM job_run'));
        self::assertSame([], $logger->cleanLogs());
    }

    public function testAnExceededThresholdIsRecordedAndLogged(): void
    {
        [$telemetry, $logger] = $this->telemetry(['allocation_projection_rebuild' => ['slow_seconds' => null, 'long_seconds' => null, 'memory_mb' => 1]]);

        $telemetry->begin(4711, 'messenger');
        $telemetry->measure('projection.insert', static fn (): int => 0);
        $telemetry->rebuilt(new AllocationStatsProjectionRebuildResult(0, 0));
        $telemetry->end();

        self::assertSame('memory', $this->connection()->fetchOne("SELECT thresholds_exceeded FROM job_run WHERE job = 'allocation_projection_rebuild'"));
        $warnings = array_values(array_filter($logger->cleanLogs(), static fn (array $log): bool => 'projection_rebuild.threshold_exceeded' === $log[1]));
        self::assertCount(1, $warnings);
        self::assertSame(4711, $warnings[0][2]['import_id']);
        self::assertSame('memory', $warnings[0][2]['thresholds']);
    }

    public function testAFailedHospitalLookupStillRecordsTheRun(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willThrowException(new \RuntimeException('connection lost'));
        [$telemetry] = $this->telemetry([], $connection);

        $telemetry->begin(4712, 'messenger');
        $telemetry->end();

        $run = $this->connection()->fetchAssociative("SELECT status, import_id, hospital_id FROM job_run WHERE job = 'allocation_projection_rebuild'");
        self::assertSame(['status' => 'succeeded', 'import_id' => 4712, 'hospital_id' => null], $run);
    }

    public function testNestedStepsOfAFullRebuildBecomeSummedPhaseSpans(): void
    {
        $transaction = new Transaction(TransactionContext::make()->setName('allocation_projection_rebuild')->setSampled(true));
        $transaction->initSpanRecorder();
        $hub = $this->createStub(HubInterface::class);
        $hub->method('startTransaction')->willReturn($transaction);
        [$telemetry] = $this->telemetry([], hub: $hub);

        $telemetry->begin(null, 'console');
        $telemetry->measure('projection.rebuild_imports', function () use ($telemetry): void {
            foreach ([1, 2, 3] as $import) {
                $telemetry->measure('projection.delete', static fn (): int => $import);
                $telemetry->measure('projection.insert', static fn (): int => $import);
                $telemetry->rebuilt(new AllocationStatsProjectionRebuildResult(1, 2));
            }
        }, ['imports' => 3]);
        $telemetry->end();

        // The first recorded span is the transaction itself.
        $children = \array_slice($transaction->getSpanRecorder()?->getSpans() ?? [], 1);
        $ops = array_map(static fn (Span $span): ?string => $span->getOp(), $children);
        self::assertSame(['projection.rebuild_imports', 'projection.delete', 'projection.insert'], $ops, 'One phase span per nested op, not one per import.');

        $run = $this->connection()->fetchAssociative("SELECT job, import_id, metrics FROM job_run WHERE job = 'allocation_projection_full_rebuild'");
        self::assertIsArray($run);
        self::assertNull($run['import_id']);
        $metrics = json_decode((string) $run['metrics'], true);
        self::assertIsArray($metrics);
        self::assertSame(3, $metrics['imports']);
        self::assertSame(3, $metrics['rows_deleted']);
        self::assertSame(6, $metrics['rows_written']);
        self::assertIsInt($metrics['delete_ms']);
        self::assertIsInt($metrics['rebuild_imports_ms']);
    }

    /**
     * @param array<string, array{slow_seconds: int|null, long_seconds: int|null, memory_mb: int|null}> $thresholds
     *
     * @return array{AllocationProjectionRunTelemetry, BufferingLogger}
     */
    private function telemetry(array $thresholds, ?Connection $lookupConnection = null, ?HubInterface $hub = null): array
    {
        $logger = new BufferingLogger();
        $this->loggers[] = $logger;
        $container = self::getContainer();

        return [new AllocationProjectionRunTelemetry(
            $container->get(JobRunRecorder::class),
            new JobRunThresholdConfig($thresholds),
            $hub ?? $container->get(HubInterface::class),
            $lookupConnection ?? $this->connection(),
            $logger,
        ), $logger];
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
