<?php

declare(strict_types=1);

namespace App\Kpi\Application\MessageHandler;

use App\Kpi\Application\Contract\KpiScheduledAggregationRunnerInterface;
use App\Kpi\Application\Message\GenerateDailyKpisMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class GenerateDailyKpisMessageHandler
{
    private const string LOCK_KEY = 'kpi-scheduled-aggregation';

    public function __construct(
        private KpiScheduledAggregationRunnerInterface $scheduledAggregationService,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(GenerateDailyKpisMessage $message): void
    {
        $lock = $this->lockFactory->createLock(self::LOCK_KEY);

        if (!$lock->acquire()) {
            $this->logger->info('Scheduled KPI aggregation skipped: another run is already in progress.', [
                'lock_key' => self::LOCK_KEY,
                'message' => $message::class,
            ]);

            return;
        }

        try {
            $this->logger->info('Scheduled KPI aggregation started.', [
                'message' => $message::class,
            ]);

            $result = $this->scheduledAggregationService->run();

            $this->logger->info('Scheduled KPI aggregation finished successfully.', [
                'dates' => $result->dates,
                'days_processed' => $result->daysProcessed,
                'total_rows' => $result->totalRows,
                'days_with_data' => $result->daysWithData,
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error('Scheduled KPI aggregation failed.', [
                'exception' => $exception,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            $lock->release();
        }
    }
}
