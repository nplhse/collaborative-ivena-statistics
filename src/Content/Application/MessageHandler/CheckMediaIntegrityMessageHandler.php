<?php

declare(strict_types=1);

namespace App\Content\Application\MessageHandler;

use App\Content\Application\Contract\MediaIntegrityCheckerInterface;
use App\Content\Application\Media\MediaMaintenanceException;
use App\Content\Application\Message\CheckMediaIntegrityMessage;
use App\Shared\Application\JobRun\CurrentJobRunInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the daily media integrity check. While a backup or restore holds the files the run is skipped, not retried:
 * the next day checks again.
 */
#[AsMessageHandler]
final readonly class CheckMediaIntegrityMessageHandler
{
    public function __construct(
        private MediaIntegrityCheckerInterface $checker,
        private CurrentJobRunInterface $jobRun,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CheckMediaIntegrityMessage $message): void
    {
        try {
            $report = $this->checker->check(false, false);
        } catch (MediaMaintenanceException) {
            $this->jobRun->markSkipped('maintenance_running');
            $this->logger->info('Scheduled media integrity check skipped: a backup, restore or media task is running.', ['message' => $message::class]);

            return;
        }

        $this->jobRun->addMetrics([
            'files' => $report->files,
            'hashed' => $report->hashed,
            'missing' => \count($report->missing),
            'damaged' => \count($report->damaged),
            'orphans_deleted' => \count($report->orphansDeleted),
            'orphans_kept' => \count($report->orphansKept),
            'leftovers_deleted' => $report->leftoversDeleted,
            'unexpected' => \count($report->unexpected),
        ]);
        $this->logger->info('Scheduled media integrity check stored.', [
            'files' => $report->files,
            'missing' => \count($report->missing),
            'damaged' => \count($report->damaged),
            'orphans_deleted' => \count($report->orphansDeleted),
            'deletion_blocked' => $report->deletionBlockedReason,
        ]);
    }
}
