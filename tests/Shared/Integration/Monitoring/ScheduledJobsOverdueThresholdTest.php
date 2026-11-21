<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Monitoring;

use App\Shared\Infrastructure\Monitoring\JobRun\JobRunThresholdConfig;
use App\Shared\Infrastructure\Monitoring\JobRun\RecordedJobsInterface;
use App\Shared\Infrastructure\Scheduler\DefaultScheduleProvider;
use App\Shared\Infrastructure\Scheduler\ScheduleContributionInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every scheduled job that writes a job run has an expected interval, so the scheduled_jobs health check reports it
 * when it stops running (ADR 019, #1455).
 */
final class ScheduledJobsOverdueThresholdTest extends KernelTestCase
{
    public function testEveryRecordedScheduledJobHasAnOverdueThreshold(): void
    {
        self::bootKernel();
        $intervals = self::getContainer()->get(JobRunThresholdConfig::class)->overdueIntervals();

        $jobs = [];
        foreach ($this->contributions() as $contribution) {
            if ($contribution instanceof RecordedJobsInterface) {
                $jobs = [...$jobs, ...array_values($contribution->recordedJobs())];
            }
        }

        self::assertNotEmpty($jobs);
        self::assertContains('media_integrity_check', $jobs);
        self::assertSame([], array_values(array_diff($jobs, array_keys($intervals))), 'jobs without overdue_after_minutes in config/packages/app.yaml');
    }

    /**
     * @return list<ScheduleContributionInterface>
     */
    private function contributions(): array
    {
        $provider = self::getContainer()->get(DefaultScheduleProvider::class);
        /** @var iterable<ScheduleContributionInterface> $contributions */
        $contributions = new \ReflectionProperty($provider, 'contributions')->getValue($provider);

        return [...$contributions];
    }
}
