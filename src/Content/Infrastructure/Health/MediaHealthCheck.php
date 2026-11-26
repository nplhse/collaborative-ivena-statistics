<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Health;

use App\Content\Domain\Entity\MediaIntegrityCheck;
use App\Content\Infrastructure\Repository\MediaIntegrityCheckRepository;
use App\Shared\Application\Health\HealthCheckContributorInterface;
use App\Shared\Application\Health\HealthCheckResult;
use App\Shared\Application\Health\HealthCheckStatus;

/**
 * Media integrity in Application Health (ADR 039). Reads only the latest stored check, never the filesystem.
 * Missing or damaged media files make the application degraded. Before the first check the state is `unchecked`
 * and healthy; a check that stops running is reported by the scheduled jobs check. Not public: `/health` shows it
 * only to the admin dashboard.
 *
 * @psalm-suppress UnusedClass Tagged via HealthCheckContributorInterface.
 */
final readonly class MediaHealthCheck implements HealthCheckContributorInterface
{
    public const string KEY = 'media';

    public function __construct(
        private MediaIntegrityCheckRepository $checks,
    ) {
    }

    #[\Override]
    public function check(): HealthCheckResult
    {
        $latest = $this->checks->latest();
        if (!$latest instanceof MediaIntegrityCheck) {
            return new HealthCheckResult(self::KEY, HealthCheckStatus::Healthy, 'unchecked');
        }

        if ($latest->hasProblems()) {
            return new HealthCheckResult(self::KEY, HealthCheckStatus::Degraded, sprintf('missing=%d damaged=%d', $latest->getMissing(), $latest->getDamaged()));
        }

        return new HealthCheckResult(self::KEY, HealthCheckStatus::Healthy, 'ok');
    }
}
