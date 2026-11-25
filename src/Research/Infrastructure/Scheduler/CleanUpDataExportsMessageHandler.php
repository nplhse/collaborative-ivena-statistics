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
 * Scheduler job `research_export_cleanup` (ADR 019 job run log): lets releases expire whose three months passed
 * without an export, marks exports whose seven days ended as expired and removes their archives, then archives no run
 * refers to and leftover working directories older than a day (ADR 045). Starting and downloading are refused on time
 * alone; this job records the states and frees the space.
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
        $releases = 0;
        foreach ($this->requests->findReleasesExpiredBy($this->clock->now()) as $release) {
            try {
                $this->releases->expireRelease($release);
                ++$releases;
            } catch (DataRequestRefused) {
                // Changed in the meantime; the next run picks it up again.
            }
        }

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
        if (0 === $releases + $expired + $orphans) {
            $this->jobRun->markSkipped('nothing_due');

            return;
        }

        $this->jobRun->addMetrics(['releases_expired' => $releases, 'exports_expired' => $expired, 'files_removed' => $orphans]);
    }
}
