<?php

declare(strict_types=1);

namespace App\Tests\Support\Storage;

use App\Storage\Application\Backup\BackupFailed;
use App\Storage\Application\Contract\DatabaseRestorerInterface;

/**
 * Records what a restore does to the database; the "database" is the row count per table.
 */
final class InMemoryDatabaseRestorer implements DatabaseRestorerInterface
{
    /** @var list<string> */
    public array $calls = [];

    /** @var ?\Closure(): void runs inside restore(), e.g. to fail it */
    public ?\Closure $onRestore = null;

    public bool $failRollback = false;

    public bool $failDiscard = false;

    /**
     * @param array<string, int> $current  rows of the current database
     * @param array<string, int> $restored rows the restore produces
     */
    public function __construct(
        public array $current = ['allocation' => 9],
        public array $restored = ['allocation' => 4, 'hospital' => 2],
    ) {
    }

    #[\Override]
    public function target(): string
    {
        return 'app on db:5432';
    }

    #[\Override]
    public function preflight(): string
    {
        $this->calls[] = 'preflight';

        return 'pg_restore 18';
    }

    #[\Override]
    public function clear(): void
    {
        $this->calls[] = 'clear';
        $this->current = [];
    }

    #[\Override]
    public function stash(string $suffix): string
    {
        $this->calls[] = 'stash '.$suffix;

        return 'pre_restore_'.$suffix;
    }

    #[\Override]
    public function restore(string $dumpFile): void
    {
        $this->calls[] = 'restore '.basename($dumpFile);
        if ($this->onRestore instanceof \Closure) {
            ($this->onRestore)();
        }
        $this->current = $this->restored;
    }

    #[\Override]
    public function tableRows(): array
    {
        return $this->current;
    }

    #[\Override]
    public function rollback(string $stash): void
    {
        $this->calls[] = 'rollback '.$stash;
        if ($this->failRollback) {
            throw new BackupFailed('rename failed.');
        }
    }

    #[\Override]
    public function discard(string $stash): void
    {
        $this->calls[] = 'discard '.$stash;
        if ($this->failDiscard) {
            throw new BackupFailed('permission denied.');
        }
    }
}
