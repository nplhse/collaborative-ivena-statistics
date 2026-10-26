<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\JobRun;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Thresholds per job from app.job_runs in config/packages/app.yaml.
 */
final readonly class JobRunThresholdConfig
{
    /**
     * @param array<string, array{slow_seconds: int|null, long_seconds: int|null, memory_mb: int|null, overdue_after_minutes?: int|null}> $thresholds
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct(
        #[Autowire(param: 'app.job_runs')]
        private array $thresholds,
    ) {
    }

    public function for(string $job): JobRunThresholds
    {
        $limits = $this->thresholds[$job] ?? null;
        if (null === $limits) {
            return new JobRunThresholds();
        }

        return new JobRunThresholds(
            $limits['slow_seconds'],
            $limits['long_seconds'],
            null === $limits['memory_mb'] ? null : $limits['memory_mb'] * 1024 * 1024,
        );
    }
}
