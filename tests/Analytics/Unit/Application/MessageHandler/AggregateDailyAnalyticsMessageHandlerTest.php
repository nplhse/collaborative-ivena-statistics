<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Unit\Application\MessageHandler;

use App\Analytics\Application\Contract\AnalyticsScheduledAggregationRunnerInterface;
use App\Analytics\Application\DTO\AnalyticsScheduledAggregationResult;
use App\Analytics\Application\Message\AggregateDailyAnalyticsMessage;
use App\Analytics\Application\MessageHandler\AggregateDailyAnalyticsMessageHandler;
use App\Shared\Infrastructure\Monitoring\JobRun\CurrentJobRun;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

final class AggregateDailyAnalyticsMessageHandlerTest extends TestCase
{
    private AnalyticsScheduledAggregationRunnerInterface&MockObject $scheduledAggregationService;

    private LockFactory&MockObject $lockFactory;

    private SharedLockInterface&MockObject $lock;

    private LoggerInterface&MockObject $logger;

    private CurrentJobRun $jobRun;

    private AggregateDailyAnalyticsMessageHandler $messageHandler;

    #[\Override]
    protected function setUp(): void
    {
        $this->jobRun = new CurrentJobRun();
        $this->jobRun->begin();
        $this->scheduledAggregationService = $this->createMock(AnalyticsScheduledAggregationRunnerInterface::class);
        $this->lockFactory = $this->createMock(LockFactory::class);
        $this->lock = $this->createMock(SharedLockInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->lockFactory->expects($this->once())->method('createLock')->willReturn($this->lock);

        $this->messageHandler = new AggregateDailyAnalyticsMessageHandler(
            $this->scheduledAggregationService,
            $this->lockFactory,
            $this->logger,
            $this->jobRun,
        );
    }

    public function testInvokesScheduledAggregationWhenLockIsAcquired(): void
    {
        $result = new AnalyticsScheduledAggregationResult(
            dates: ['2026-09-06', '2026-09-07'],
            daysProcessed: 2,
            totalRawRequests: 10,
            totalRawEvents: 4,
            totalAggregateRows: 8,
            rawRowsDeleted: 3,
        );

        $this->lock->expects($this->once())->method('acquire')->willReturn(true);
        $this->lock->expects($this->once())->method('release');

        $this->scheduledAggregationService->expects($this->once())
            ->method('run')
            ->willReturn($result);

        $this->logger->expects($this->exactly(2))->method('info');

        ($this->messageHandler)(new AggregateDailyAnalyticsMessage());

        self::assertSame(['days_processed' => 2, 'raw_requests' => 10, 'raw_events' => 4, 'aggregate_rows' => 8, 'raw_rows_deleted' => 3], $this->jobRun->metrics());
    }

    public function testSkipsWhenLockCannotBeAcquired(): void
    {
        $this->lock->expects($this->once())->method('acquire')->willReturn(false);
        $this->lock->expects($this->never())->method('release');

        $this->scheduledAggregationService->expects($this->never())->method('run');

        $this->logger->expects($this->once())->method('info');

        ($this->messageHandler)(new AggregateDailyAnalyticsMessage());

        self::assertSame('already_running', $this->jobRun->skipReason());
    }

    public function testLogsAndRethrowsOnFailure(): void
    {
        $exception = new \RuntimeException('Aggregation failed.');

        $this->lock->expects($this->once())->method('acquire')->willReturn(true);
        $this->lock->expects($this->once())->method('release');

        $this->scheduledAggregationService->expects($this->once())
            ->method('run')
            ->willThrowException($exception);

        $this->logger->expects($this->once())->method('info');
        $this->logger->expects($this->once())->method('error');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Aggregation failed.');

        ($this->messageHandler)(new AggregateDailyAnalyticsMessage());
    }
}
