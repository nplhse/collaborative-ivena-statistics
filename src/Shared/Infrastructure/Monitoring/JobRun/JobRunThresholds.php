<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\JobRun;

/**
 * Duration and memory limits of one job (app.job_runs). A null limit is not checked,
 * so a job without configured values only records its runs.
 */
final readonly class JobRunThresholds
{
    public function __construct(
        public ?int $slowSeconds = null,
        public ?int $longSeconds = null,
        public ?int $memoryBytes = null,
    ) {
    }

    /**
     * @return list<'long_run'|'slow'|'memory'> long_run replaces slow
     */
    public function exceeded(float $seconds, int $peakMemoryBytes): array
    {
        $exceeded = [];
        if (null !== $this->longSeconds && $seconds > $this->longSeconds) {
            $exceeded[] = 'long_run';
        } elseif (null !== $this->slowSeconds && $seconds > $this->slowSeconds) {
            $exceeded[] = 'slow';
        }
        if (null !== $this->memoryBytes && $peakMemoryBytes > $this->memoryBytes) {
            $exceeded[] = 'memory';
        }

        return $exceeded;
    }
}
