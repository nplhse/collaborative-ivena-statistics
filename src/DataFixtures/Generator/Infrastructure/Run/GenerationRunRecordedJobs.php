<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Infrastructure\Run;

use App\DataFixtures\Generator\Application\Run\Message\FinishGenerationRun;
use App\DataFixtures\Generator\Application\Run\Message\ProjectGeneratedHospital;
use App\Shared\Infrastructure\Monitoring\JobRun\RecordedJobsInterface;

/**
 * Records the asynchronous stages of generation runs in job_run (ADR 019), so they appear in the admin list of
 * job runs with `generation_run_id` in their metrics. Registered only where the generator services exist.
 */
final readonly class GenerationRunRecordedJobs implements RecordedJobsInterface
{
    public const string JOB_PROJECT = 'generation_project_hospital';
    public const string JOB_FINISH = 'generation_finish_run';

    #[\Override]
    public function recordedJobs(): array
    {
        return [
            ProjectGeneratedHospital::class => self::JOB_PROJECT,
            FinishGenerationRun::class => self::JOB_FINISH,
        ];
    }
}
