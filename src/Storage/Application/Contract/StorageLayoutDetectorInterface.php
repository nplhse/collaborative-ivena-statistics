<?php

declare(strict_types=1);

namespace App\Storage\Application\Contract;

use App\Storage\Application\Layout\StorageLayout;

/**
 * Finds out whether the application runs from a Deployer release and what belongs to the deployment
 * (ADR 017, "Deployment layout"). Reads the layout on disk, never deploy.php.
 */
interface StorageLayoutDetectorInterface
{
    public function detect(): StorageLayout;
}
