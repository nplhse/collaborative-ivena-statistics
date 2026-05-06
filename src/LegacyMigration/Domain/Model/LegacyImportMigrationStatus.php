<?php

declare(strict_types=1);

namespace App\LegacyMigration\Domain\Model;

enum LegacyImportMigrationStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
}

