<?php

declare(strict_types=1);

namespace App\Storage\Application\MessageHandler;

use App\Shared\Application\JobRun\CurrentJobRunInterface;
use App\Storage\Application\Measurement\StorageMeasurementInProgress;
use App\Storage\Application\Measurement\StorageMeasurementRecorder;
use App\Storage\Application\Message\MeasureStorageMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the daily measurement. A run that finds another one in progress is skipped, not retried.
 */
#[AsMessageHandler]
final readonly class MeasureStorageMessageHandler
{
    public function __construct(
        private StorageMeasurementRecorder $recorder,
        private LoggerInterface $logger,
        private CurrentJobRunInterface $jobRun,
    ) {
    }

    public function __invoke(MeasureStorageMessage $message): void
    {
        try {
            $recorded = $this->recorder->record();
        } catch (StorageMeasurementInProgress) {
            $this->jobRun->markSkipped('already_running');
            $this->logger->info('Scheduled storage measurement skipped: another measurement is in progress.', [
                'message' => $message::class,
            ]);

            return;
        }

        $measurement = $recorded->measurement;
        $this->jobRun->addMetrics([
            'level' => $measurement->getLevel()->value,
            'failures' => \count($recorded->analysis->failures),
        ]);
        $this->logger->info('Scheduled storage measurement stored.', [
            'measured_on' => $measurement->getMeasuredOn()->format('Y-m-d'),
            'used_bytes' => $measurement->getUsedBytes(),
            'level' => $measurement->getLevel()->value,
            'duration_ms' => $measurement->getDurationMs(),
            'failures' => \count($recorded->analysis->failures),
            'message' => $message::class,
        ]);
    }
}
