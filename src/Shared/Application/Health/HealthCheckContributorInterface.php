<?php

declare(strict_types=1);

namespace App\Shared\Application\Health;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Adds a check to Application Health without Shared depending on the feature context (ADR 017).
 * Contributors run only after the database check succeeded, so they may query the database.
 */
#[AutoconfigureTag(self::TAG)]
interface HealthCheckContributorInterface
{
    public const string TAG = 'app.health_check_contributor';

    public function check(): HealthCheckResult;
}
