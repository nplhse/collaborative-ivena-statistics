<?php

declare(strict_types=1);

namespace App\Storage\Domain\Enum;

/**
 * Deployer when the project runs from releases/<name> with a current symlink and a shared directory,
 * standalone otherwise (ADR 017, "Deployment layout").
 */
enum MeasurementMode: string
{
    case Deployer = 'deployer';
    case Standalone = 'standalone';
}
