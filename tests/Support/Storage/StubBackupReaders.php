<?php

declare(strict_types=1);

namespace App\Tests\Support\Storage;

use App\Storage\Application\Backup\BackupVerifier;
use App\Storage\Application\Contract\ArchiveExtractorInterface;
use App\Storage\Application\Contract\ArchiveReaderInterface;
use App\Storage\Application\Contract\DumpReaderInterface;

/**
 * Readers and an extractor for backups written by BackupDirectoryBuilder, whose payloads are placeholders.
 */
final class StubBackupReaders
{
    /**
     * @param list<string> $tables tables the dump holds data for
     */
    public static function dumpReader(array $tables = ['allocation', 'hospital']): DumpReaderInterface
    {
        return new readonly class($tables) implements DumpReaderInterface {
            /**
             * @param list<string> $tables
             */
            public function __construct(private array $tables)
            {
            }

            #[\Override]
            public function preflight(): string
            {
                return 'pg_restore 18';
            }

            #[\Override]
            public function tables(string $dumpFile): array
            {
                return $this->tables;
            }
        };
    }

    /**
     * @param array<string, string> $mediaFiles
     */
    public static function archiveReader(array $mediaFiles = ['a.txt' => 'alpha', 'b/c.txt' => 'gamma']): ArchiveReaderInterface
    {
        return new readonly class($mediaFiles) implements ArchiveReaderInterface {
            /**
             * @param array<string, string> $mediaFiles
             */
            public function __construct(private array $mediaFiles)
            {
            }

            #[\Override]
            public function entries(string $archiveFile): array
            {
                return BackupDirectoryBuilder::entries($this->mediaFiles);
            }
        };
    }

    /**
     * Writes $mediaFiles into the target directory, as extracting the placeholder archive would.
     *
     * @param array<string, string> $mediaFiles
     */
    public static function extractor(array $mediaFiles = ['a.txt' => 'alpha', 'b/c.txt' => 'gamma']): ArchiveExtractorInterface
    {
        return new readonly class($mediaFiles) implements ArchiveExtractorInterface {
            /**
             * @param array<string, string> $mediaFiles
             */
            public function __construct(private array $mediaFiles)
            {
            }

            #[\Override]
            public function extract(string $archiveFile, string $targetDirectory): void
            {
                foreach ($this->mediaFiles as $path => $content) {
                    $file = $targetDirectory.'/'.$path;
                    if (!is_dir(\dirname($file))) {
                        mkdir(\dirname($file), 0o777, true);
                    }
                    file_put_contents($file, $content);
                }
            }
        };
    }

    public static function verifier(): BackupVerifier
    {
        return new BackupVerifier(self::dumpReader(), self::archiveReader());
    }
}
