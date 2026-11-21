<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Media;

use App\Content\Application\Contract\MediaIntegrityCheckerInterface;
use App\Content\Application\Event\MediaIntegrityChecked;
use App\Content\Application\Media\MediaIntegrityReport;
use App\Content\Application\Media\MediaMaintenanceException;
use App\Content\Domain\Entity\MediaIntegrityCheck;
use App\Content\Infrastructure\Repository\MediaIntegrityCheckRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;

/**
 * The media integrity check (ADR 039, "Integrity check and cleanup").
 *
 * Every run checks existence and size of the file of each media item and lists the files no media item refers to.
 * It also hashes the files verified longest ago up to a byte budget, so every file is hashed about once a month
 * without reading all of them each day; a full run hashes all. Missing and damaged files are only reported.
 *
 * Files no media item refers to (below `originals/` and `variants/`) and leftovers in `.tmp/` are deleted once they
 * are older than the grace period, which protects an upload whose row is not committed yet. Orphans are kept and the
 * run only reports when the library is empty or when they are many and a large share of the files: that points to a
 * wrong database or an incomplete restore, not to leftovers.
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for MediaIntegrityCheckerInterface.
 */
#[AsAlias(MediaIntegrityCheckerInterface::class)]
final readonly class MediaIntegrityChecker implements MediaIntegrityCheckerInterface
{
    /** Orphans above this share of all files, and above {@see MIN_BLOCKING_ORPHANS}, block every deletion of the run. */
    public const float MAX_ORPHAN_SHARE = 0.2;

    /** A few orphans are always deleted, so a small library is not blocked by one leftover file. */
    public const int MIN_BLOCKING_ORPHANS = 10;

    private const int RETENTION_DAYS = 90;
    private const string KEY_PATTERN = '#^originals/\d{4}/\d{2}/[0-9a-f-]{36}\.[a-z0-9]{1,8}$#';
    private const string VARIANT_PATTERN = '#^variants/\d{4}/\d{2}/([0-9a-f-]{36})$#';

    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        private MediaStorage $storage,
        private Connection $connection,
        private MediaIntegrityCheckRepository $checks,
        private LockFactory $lockFactory,
        private ClockInterface $clock,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
        #[Autowire(param: 'app.media_check_hash_budget_bytes')]
        private int $hashBudgetBytes,
        #[Autowire(param: 'app.media_check_grace_hours')]
        private int $graceHours,
    ) {
    }

    #[\Override]
    public function check(bool $full, bool $dryRun): MediaIntegrityReport
    {
        $lock = $this->lockFactory->createLock(MediaStorageMigrator::LOCK, null);
        if (!$lock->acquire()) {
            throw new MediaMaintenanceException('A backup, restore or media task is running. The integrity check runs again later.');
        }

        try {
            $report = $this->run($full, $dryRun);
        } finally {
            $lock->release();
        }

        if (!$dryRun) {
            $this->store($report);
            $this->eventDispatcher->dispatch(new MediaIntegrityChecked($report));
        }

        return $report;
    }

    private function run(bool $full, bool $dryRun): MediaIntegrityReport
    {
        $now = $this->clock->now();
        $rows = $this->storedMedia();
        $scan = $this->scan();

        $missing = [];
        $damaged = [];
        $present = [];
        foreach ($rows as $key => $row) {
            $size = $scan['originals'][$key] ?? null;
            if (null === $size) {
                $missing[] = $key;
                continue;
            }

            if (null !== $row['size'] && $size !== $row['size']) {
                $damaged[] = $key;
                continue;
            }

            $present[$key] = $row;
        }

        [$hashed, $hashDamaged] = $this->hash($present, $full, $dryRun, $now);
        $damaged = [...$damaged, ...$hashDamaged];

        $orphans = array_keys(array_diff_key($scan['originals'], $rows));
        $uuids = [];
        foreach (array_keys($rows) as $key) {
            $uuids[pathinfo($key, \PATHINFO_FILENAME)] = true;
        }
        foreach ($scan['variants'] as $path => $uuid) {
            if (!isset($uuids[$uuid])) {
                $orphans[] = $path;
            }
        }
        sort($orphans);

        $blocked = match (true) {
            [] === $orphans => null,
            $dryRun => MediaIntegrityReport::BLOCKED_DRY_RUN,
            [] === $rows => MediaIntegrityReport::BLOCKED_EMPTY_LIBRARY,
            (float) \count($orphans) > max((float) self::MIN_BLOCKING_ORPHANS, self::MAX_ORPHAN_SHARE * (float) \count($scan['originals'])) => MediaIntegrityReport::BLOCKED_ORPHAN_SHARE,
            default => null,
        };

        $cutoff = $now->getTimestamp() - $this->graceHours * 3600;
        $deleted = [];
        $kept = [];
        foreach ($orphans as $path) {
            if (null === $blocked && $this->modifiedAt($path) < $cutoff) {
                $this->delete($path, 'orphan');
                $deleted[] = $path;
            } else {
                $kept[] = $path;
            }
        }

        $leftovers = 0;
        foreach ($scan['temporary'] as $path) {
            if (!$dryRun && $this->modifiedAt($path) < $cutoff) {
                $this->delete($path, 'leftover');
                ++$leftovers;
            }
        }

        if ([] !== $deleted) {
            $this->removeEmptyDirectories();
        }

        return new MediaIntegrityReport(
            $now,
            $full,
            \count($scan['originals']),
            array_sum($scan['originals']),
            $hashed,
            $missing,
            $damaged,
            $deleted,
            $kept,
            $leftovers,
            $scan['unexpected'],
            $blocked,
        );
    }

    /**
     * @return array<string, array{id: int, size: ?int, checksum: ?string}> media items with a stored file, by key
     */
    private function storedMedia(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, storage_key, size, checksum_sha256 FROM media WHERE storage_key IS NOT NULL');
        $media = [];
        foreach ($rows as $row) {
            $media[(string) $row['storage_key']] = [
                'id' => (int) $row['id'],
                'size' => null !== $row['size'] ? (int) $row['size'] : null,
                'checksum' => \is_string($row['checksum_sha256']) ? $row['checksum_sha256'] : null,
            ];
        }

        return $media;
    }

    /**
     * Files below the storage root, by where they belong in the layout.
     *
     * @return array{originals: array<string, int>, variants: array<string, string>, temporary: list<string>, unexpected: list<string>}
     */
    private function scan(): array
    {
        $result = ['originals' => [], 'variants' => [], 'temporary' => [], 'unexpected' => []];
        $root = $this->storage->root();
        if (!is_dir($root)) {
            return $result;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }

            $path = substr($entry->getPathname(), \strlen($root) + 1);
            $top = explode('/', $path, 2)[0];

            if (MediaStorage::VARIANTS === $top && $entry->isDir() && !$entry->isLink()) {
                if (1 === preg_match(self::VARIANT_PATTERN, $path, $match)) {
                    $result['variants'][$path] = $match[1];
                }
                continue;
            }

            if ($entry->isDir() && !$entry->isLink()) {
                continue;
            }

            if (MediaStorage::TEMPORARY === $top && $entry->isFile() && !$entry->isLink()) {
                $result['temporary'][] = $path;
            } elseif (MediaStorage::ORIGINALS === $top && $entry->isFile() && !$entry->isLink() && 1 === preg_match(self::KEY_PATTERN, $path)) {
                $result['originals'][$path] = (int) $entry->getSize();
            } elseif (MediaStorage::VARIANTS !== $top || $entry->isLink() || 1 !== preg_match('#^variants/\d{4}/\d{2}/[0-9a-f-]{36}/[^/]+$#', $path)) {
                $result['unexpected'][] = $path;
            }
        }

        sort($result['unexpected']);

        return $result;
    }

    /**
     * Hashes the files with the oldest verification first, all of them for a full run.
     *
     * @param array<string, array{id: int, size: ?int, checksum: ?string}> $present
     *
     * @return array{int, list<string>} number of hashed files and keys whose content differs
     */
    private function hash(array $present, bool $full, bool $dryRun, \DateTimeImmutable $now): array
    {
        $order = $this->connection->fetchFirstColumn('SELECT storage_key FROM media WHERE storage_key IS NOT NULL ORDER BY verified_at ASC NULLS FIRST, id ASC');
        $budget = $this->hashBudgetBytes;
        $hashed = 0;
        $damaged = [];

        foreach ($order as $key) {
            $key = (string) $key;
            if (!isset($present[$key])) {
                continue;
            }

            $row = $present[$key];
            if (!$full && $hashed > 0 && $budget <= 0) {
                break;
            }

            $path = $this->storage->absolutePath($key);
            $budget -= (int) filesize($path);
            $checksum = hash_file('sha256', $path);
            ++$hashed;

            if (false === $checksum || (null !== $row['checksum'] && !hash_equals($row['checksum'], $checksum))) {
                $damaged[] = $key;
                continue;
            }

            if (!$dryRun) {
                $this->connection->executeStatement(
                    'UPDATE media SET verified_at = :now, checksum_sha256 = COALESCE(checksum_sha256, :checksum) WHERE id = :id',
                    ['now' => $now->format('Y-m-d H:i:s'), 'checksum' => $checksum, 'id' => $row['id']],
                    ['id' => Types::INTEGER],
                );
            }
        }

        return [$hashed, $damaged];
    }

    private function modifiedAt(string $path): int
    {
        $modified = @filemtime($this->storage->root().'/'.$path);

        return false === $modified ? \PHP_INT_MAX : $modified;
    }

    private function delete(string $path, string $reason): void
    {
        $absolute = $this->storage->root().'/'.$path;
        $this->logger->info('Media integrity check deletes {reason} {path}.', [
            'reason' => $reason,
            'path' => $path,
            'bytes' => is_file($absolute) ? (int) filesize($absolute) : null,
            'modified_at' => date(\DATE_ATOM, $this->modifiedAt($path)),
        ]);
        new Filesystem()->remove($absolute);
    }

    /** Removes month and year directories that the deletion left empty. */
    private function removeEmptyDirectories(): void
    {
        foreach ([MediaStorage::ORIGINALS, MediaStorage::VARIANTS] as $top) {
            foreach ([$top.'/*/*', $top.'/*'] as $pattern) {
                $directories = glob($this->storage->root().'/'.$pattern, \GLOB_ONLYDIR);
                foreach (false === $directories ? [] : $directories as $directory) {
                    @rmdir($directory);
                }
            }
        }
    }

    private function store(MediaIntegrityReport $report): void
    {
        $findings = [
            ...array_map(static fn (string $key): array => ['kind' => 'missing', 'key' => $key], $report->missing),
            ...array_map(static fn (string $key): array => ['kind' => 'damaged', 'key' => $key], $report->damaged),
        ];

        $this->checks->save(new MediaIntegrityCheck(
            $report->checkedAt,
            $report->full,
            $report->files,
            $report->bytes,
            $report->hashed,
            \count($report->missing),
            \count($report->damaged),
            \count($report->orphansDeleted),
            \count($report->orphansKept),
            \count($report->unexpected),
            $report->deletionBlockedReason,
            $findings,
        ));
        $deleted = $this->checks->deleteCheckedBefore($report->checkedAt->modify(sprintf('-%d days', self::RETENTION_DAYS)));
        if ($deleted > 0) {
            $this->logger->info('Removed {count} media integrity check results older than {days} days.', ['count' => $deleted, 'days' => self::RETENTION_DAYS]);
        }
    }
}
