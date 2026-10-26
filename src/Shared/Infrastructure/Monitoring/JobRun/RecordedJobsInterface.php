<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\JobRun;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Names the messages whose handling JobRunMiddleware records in job_run (ADR 019). Each context implements it
 * in its Infrastructure layer, usually next to its schedule; the message classes stay unchanged.
 */
#[AutoconfigureTag(self::TAG)]
interface RecordedJobsInterface
{
    public const string TAG = 'app.job_run.recorded_jobs';

    /**
     * @return array<class-string, string> message class => job name (job_run.job)
     */
    public function recordedJobs(): array;
}
