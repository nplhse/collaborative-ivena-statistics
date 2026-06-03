<?php

declare(strict_types=1);

namespace App\Tests\Kpi\Unit\Application\MessageHandler;

use App\Kpi\Application\Contract\KpiScheduledAggregationRunnerInterface;
use App\Kpi\Application\DTO\KpiScheduledAggregationResult;
use App\Kpi\Application\Message\GenerateDailyKpisMessage;
use App\Kpi\Application\MessageHandler\GenerateDailyKpisMessageHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

final class GenerateDailyKpisMessageHandlerTest extends TestCase
{
    private KpiScheduledAggregationRunnerInterface&MockObject $scheduledAggregationService;

    private LockFactory&MockObject $lockFactory;

    private SharedLockInterface&MockObject $lock;

    private LoggerInterface&MockObject $logger;

    private GenerateDailyKpisMessageHandler $messageHandler;

    protected function setUp(): void
    {
        $this->scheduledAggregationService = $this->createMock(KpiScheduledAggregationRunnerInterface::class);
        $this->lockFactory = $this->createMock(LockFactory::class);
        $this->lock = $this->createMock(SharedLockInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->lockFactory->method('createLock')->willReturn($this->lock);

        $this->messageHandler = new GenerateDailyKpisMessageHandler(
            $this->scheduledAggregationService,
            $this->lockFactory,
            $this->logger,
        );
    }

    public function testInvokesScheduledAggregationWhenLockIsAcquired(): void
    {
        $result = new KpiScheduledAggregationResult(
            dates: ['2026-06-02', '2026-06-03'],
            daysProcessed: 2,
            totalRows: 3,
            daysWithData: 1,
        );

        $this->lock->expects($this->once())->method('acquire')->willReturn(true);
        $this->lock->expects($this->once())->method('release');

        $this->scheduledAggregationService->expects($this->once())
            ->method('run')
            ->willReturn($result);

        $this->logger->expects($this->exactly(2))->method('info');

        ($this->messageHandler)(new GenerateDailyKpisMessage());
    }

    public function testSkipsWhenLockCannotBeAcquired(): void
    {
        $this->lock->expects($this->once())->method('acquire')->willReturn(false);
        $this->lock->expects($this->never())->method('release');

        $this->scheduledAggregationService->expects($this->never())->method('run');

        $this->logger->expects($this->once())->method('info');

        ($this->messageHandler)(new GenerateDailyKpisMessage());
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

        ($this->messageHandler)(new GenerateDailyKpisMessage());
    }
}
