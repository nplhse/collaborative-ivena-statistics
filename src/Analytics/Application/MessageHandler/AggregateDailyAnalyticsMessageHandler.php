<?php

declare(strict_types=1);

namespace App\Analytics\Application\MessageHandler;

use App\Analytics\Application\Contract\AnalyticsScheduledAggregationRunnerInterface;
use App\Analytics\Application\Message\AggregateDailyAnalyticsMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class AggregateDailyAnalyticsMessageHandler
{
    private const string LOCK_KEY = 'analytics-scheduled-aggregation';

    public function __construct(
        private AnalyticsScheduledAggregationRunnerInterface $scheduledAggregationService,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(AggregateDailyAnalyticsMessage $message): void
    {
        $lock = $this->lockFactory->createLock(self::LOCK_KEY);

        if (!$lock->acquire()) {
            $this->logger->info('Scheduled analytics aggregation skipped: another run is already in progress.', [
                'lock_key' => self::LOCK_KEY,
                'message' => $message::class,
            ]);

            return;
        }

        try {
            $this->logger->info('Scheduled analytics aggregation started.', [
                'message' => $message::class,
            ]);

            $result = $this->scheduledAggregationService->run();

            $this->logger->info('Scheduled analytics aggregation finished successfully.', [
                'dates' => $result->dates,
                'days_processed' => $result->daysProcessed,
                'raw_requests' => $result->totalRawRequests,
                'raw_events' => $result->totalRawEvents,
                'aggregate_rows' => $result->totalAggregateRows,
                'raw_rows_deleted' => $result->rawRowsDeleted,
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error('Scheduled analytics aggregation failed.', [
                'exception' => $exception,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            $lock->release();
        }
    }
}
