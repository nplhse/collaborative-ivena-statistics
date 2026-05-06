<?php

declare(strict_types=1);

namespace App\LegacyMigration\Domain\Model;

enum LegacyMigrationRunStatus: string
{
    case Installed = 'installed';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
}

