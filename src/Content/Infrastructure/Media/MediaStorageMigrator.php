<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Media;

use App\Content\Application\Contract\MediaStorageMigratorInterface;
use App\Content\Application\Media\MediaMaintenanceException;
use App\Content\Application\Media\MediaStorageMigrationResult;
use App\Content\Domain\Entity\Media;
use App\Content\Infrastructure\Repository\MediaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Lock\LockFactory;

/**
 * Moves media items from the old upload directory into the media storage (ADR 039, "Migration of existing files").
 *
 * Every file is copied, not moved, into the month of its media item and compared with its source by SHA-256; only
 * then the row gets its storage key, in its own flush. A run that stops halfway leaves the remaining rows as they
 * were, and a second run skips the moved ones. The old directory is not changed, so the previous release keeps
 * working; when it is the storage root itself (flat files of a backup from before ADR 039), each verified source is
 * removed instead, because nothing else reads it.
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for MediaStorageMigratorInterface.
 */
#[AsAlias(MediaStorageMigratorInterface::class)]
final readonly class MediaStorageMigrator implements MediaStorageMigratorInterface
{
    /** Lock shared with backup and restore (ADR 038): files must not move while a backup reads them. */
    public const string LOCK = 'backup-maintenance';

    private const string EXTENSION_PATTERN = '#^[a-z0-9]{1,8}$#';

    /** @var array<string, string> */
    private const array EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    /** Files the old directory holds for the web server, not as media. */
    private const array IGNORED_FILES = ['.gitkeep', '.htaccess'];

    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        private MediaRepository $mediaRepository,
        private MediaStorage $storage,
        private EntityManagerInterface $entityManager,
        private LockFactory $lockFactory,
        private ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function migrate(string $legacyDir, bool $dryRun, callable $progress): MediaStorageMigrationResult
    {
        $lock = $this->lockFactory->createLock(self::LOCK, null);
        if (!$lock->acquire()) {
            throw new MediaMaintenanceException('A backup, restore or media task is running. Wait until it has finished and try again.');
        }

        // A relative directory (as documented for restores: --legacy-dir=var/media) is relative to the working directory.
        $cwd = getcwd();
        $legacyDir = Path::makeAbsolute($legacyDir, false === $cwd ? $this->storage->root() : $cwd);

        try {
            return $this->run(rtrim($legacyDir, '/'), $dryRun, $progress);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param callable(string $filename, string $outcome): void $progress
     */
    private function run(string $legacyDir, bool $dryRun, callable $progress): MediaStorageMigrationResult
    {
        $removeSources = !$dryRun && Path::canonicalize($legacyDir) === Path::canonicalize($this->storage->root());
        $moved = 0;
        $movedBytes = 0;
        $missing = [];
        $failed = [];

        foreach ($this->mediaRepository->findIdsNotMoved() as $id) {
            $media = $this->mediaRepository->findOneById($id);
            $filename = $media?->getFilename();
            if (!$media instanceof Media || null === $filename || null !== $media->getStorageKey()) {
                continue;
            }

            $source = $this->sourcePath($legacyDir, $filename);
            if (null === $source || !is_file($source)) {
                $missing[] = $filename;
                $progress($filename, 'missing');
                continue;
            }

            if ($dryRun) {
                ++$moved;
                $movedBytes += (int) filesize($source);
                $progress($filename, 'would move');
                continue;
            }

            try {
                $size = $this->move($media, $source);
            } catch (MediaStorageException $exception) {
                $failed[] = sprintf('%s: %s', $filename, $exception->getMessage());
                $progress($filename, 'failed');
                $this->entityManager->clear();
                continue;
            }

            if ($removeSources) {
                @unlink($source);
            }

            ++$moved;
            $movedBytes += $size;
            $progress($filename, 'moved');
            $this->entityManager->clear();
        }

        return new MediaStorageMigrationResult($moved, $movedBytes, $missing, $failed, $this->unlinkedFiles($legacyDir));
    }

    /**
     * @throws MediaStorageException when the copy differs from its source or cannot be written
     */
    private function move(Media $media, string $source): int
    {
        $sourceChecksum = hash_file('sha256', $source);
        if (false === $sourceChecksum) {
            throw new MediaStorageException('The file cannot be read.');
        }

        $stored = $this->storage->store($source, $this->extension($media, $source), $media->getCreatedAt());
        if (!hash_equals($sourceChecksum, $stored->checksumSha256) || $stored->checksumSha256 !== hash_file('sha256', $this->storage->absolutePath($stored->storageKey))) {
            $this->storage->delete($stored->storageKey);

            throw new MediaStorageException('The copy differs from the original file.');
        }

        $media->attachStoredFile($stored->storageKey, $stored->size, $stored->checksumSha256, $this->clock->now());
        try {
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $this->storage->delete($stored->storageKey);

            throw $exception;
        }

        return $stored->size;
    }

    private function sourcePath(string $legacyDir, string $filename): ?string
    {
        if (str_contains($filename, '/') || str_contains($filename, '\\') || \in_array($filename, ['.', '..'], true)) {
            return null;
        }

        return $legacyDir.'/'.$filename;
    }

    private function extension(Media $media, string $source): string
    {
        $extension = strtolower(pathinfo($source, \PATHINFO_EXTENSION));
        if (1 === preg_match(self::EXTENSION_PATTERN, $extension)) {
            return $extension;
        }

        return self::EXTENSIONS[(string) $media->getMimeType()] ?? 'bin';
    }

    /**
     * @return list<string>
     */
    private function unlinkedFiles(string $legacyDir): array
    {
        if (!is_dir($legacyDir)) {
            return [];
        }

        $referenced = array_flip($this->mediaRepository->findLegacyFilenames());
        $unlinked = [];
        foreach (new \DirectoryIterator($legacyDir) as $entry) {
            if (!$entry->isFile() || $entry->isLink() || \in_array($entry->getFilename(), self::IGNORED_FILES, true)) {
                continue;
            }

            if (!isset($referenced[$entry->getFilename()])) {
                $unlinked[] = $entry->getFilename();
            }
        }

        sort($unlinked);

        return $unlinked;
    }
}
