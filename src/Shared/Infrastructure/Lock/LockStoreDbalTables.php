<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Lock;

use App\Shared\Application\Database\DbalManagedTablesInterface;

/**
 * Table of the Symfony lock store when `LOCK_DSN` points to the database: the store creates it on first use, no entity
 * maps it, and the backup skips its rows (`BackupFormat::RUNTIME_TABLES`). Registered so that the schema diff does
 * not propose to drop it (#1752).
 *
 * @psalm-suppress UnusedClass Tagged via DbalManagedTablesInterface.
 */
final readonly class LockStoreDbalTables implements DbalManagedTablesInterface
{
    #[\Override]
    public function tables(): array
    {
        return ['lock_keys'];
    }
}
