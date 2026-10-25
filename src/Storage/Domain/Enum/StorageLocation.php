<?php

declare(strict_types=1);

namespace App\Storage\Domain\Enum;

/**
 * Where a measured item lives (ADR 017). Deployer mode uses the release and shared locations, standalone mode
 * uses the project directory. The database is measured separately from the files.
 */
enum StorageLocation: string
{
    case CurrentRelease = 'current_release';
    case PreviousRelease = 'previous_release';
    case Shared = 'shared';
    case DeployRoot = 'deploy_root';
    case Project = 'project';
    case Database = 'database';

    public function labelKey(): string
    {
        return 'storage.location.'.$this->value;
    }
}
