<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Scheduler;

use App\Research\Application\DataRequest\DataRequestRefused;
use App\Research\Application\DataRequest\DataRequestReleaseService;
use App\Research\Application\Message\CleanUpDataExportsMessage;
use App\Research\Infrastructure\Repository\DataRequestRepository;
use App\Shared\Application\JobRun\CurrentJobRunInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Scheduler job `research_export_cleanup` (ADR 019 job run log): marks exports whose seven days ended as expired and
 * removes their archives, then archives no run refers to and leftover working directories older than a day (ADR 045).
 * The download route refuses expired exports on its own; this job frees the space.
 *
 * @psalm-suppress UnusedClass Registered via #[AsMessageHandler].
 */
#[AsMessageHandler]
final readonly class CleanUpDataExportsMessageHandler
{
    public function __construct(
        private DataRequestRepository $requests,
        private DataRequestReleaseService $releases,
        private ClockInterface $clock,
        private CurrentJobRunInterface $jobRun,
    ) {
    }

    /** @psalm-suppress UnusedParam The message type selects the handler; it carries no data. */
    public function __invoke(CleanUpDataExportsMessage $message): void
    {
        $expired = 0;
        foreach ($this->requests->findExportRunsExpiredBy($this->clock->now()) as $run) {
            try {
                $this->releases->expire($run);
                ++$expired;
            } catch (DataRequestRefused) {
                // Changed in the meantime; the next run picks it up again.
            }
        }

        $orphans = $this->releases->removeOrphans($this->requests->findArchiveFilesInUse());
        if (0 === $expired + $orphans) {
            $this->jobRun->markSkipped('nothing_due');

            return;
        }

        $this->jobRun->addMetrics(['exports_expired' => $expired, 'files_removed' => $orphans]);
    }
}
