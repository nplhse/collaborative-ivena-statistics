<?php

declare(strict_types=1);

namespace App\Content\UI\Console\Command;

use App\Content\Application\Contract\MediaIntegrityCheckerInterface;
use App\Content\Application\Media\MediaIntegrityReport;
use App\Content\Application\Media\MediaMaintenanceException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs the media integrity check by hand (ADR 039); the scheduler runs it daily. Exits with 1 when media files are
 * missing or damaged, so it can be used in scripts.
 */
#[AsCommand(
    name: 'app:media:check',
    description: 'Check the media files against the media library and remove files no media item refers to.',
)]
final readonly class CheckMediaCommand
{
    private const int LIST_LIMIT = 20;

    public function __construct(
        private MediaIntegrityCheckerInterface $checker,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Hash every file instead of the ones verified longest ago', name: 'full')]
        bool $full = false,
        #[Option(description: 'Only report; delete and store nothing', name: 'dry-run')]
        bool $dryRun = false,
    ): int {
        $io->title('Check media files');

        try {
            $report = $this->checker->check($full, $dryRun);
        } catch (MediaMaintenanceException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->table(['', 'Files'], [
            ['Stored files', sprintf('%d (%s)', $report->files, Helper::formatMemory($report->bytes))],
            ['Checksums verified', (string) $report->hashed],
            ['Missing', (string) \count($report->missing)],
            ['Damaged', (string) \count($report->damaged)],
            [$dryRun ? 'Orphans that would be deleted' : 'Orphans deleted', (string) \count($dryRun ? $report->orphansKept : $report->orphansDeleted)],
            ['Orphans kept', $dryRun ? '-' : (string) \count($report->orphansKept)],
            ['Leftovers deleted', (string) $report->leftoversDeleted],
            ['Outside the layout', (string) \count($report->unexpected)],
        ]);

        $this->list($io, 'Media items whose file is missing:', $report->missing, 'error');
        $this->list($io, 'Files that differ from their media item (size or checksum):', $report->damaged, 'error');
        $this->list($io, 'Files outside the storage layout (reported only):', $report->unexpected, 'note');
        if ($io->isVerbose()) {
            $this->list($io, 'Deleted orphans:', $report->orphansDeleted, 'note');
        }

        if (MediaIntegrityReport::BLOCKED_EMPTY_LIBRARY === $report->deletionBlockedReason) {
            $io->warning('No orphan was deleted: the media library is empty. Check that the application uses the right database.');
        } elseif (MediaIntegrityReport::BLOCKED_ORPHAN_SHARE === $report->deletionBlockedReason) {
            $io->warning('No orphan was deleted: they are too many to be leftovers. Check database and storage, for example after a restore.');
        } elseif (!$dryRun && [] !== $report->orphansKept) {
            $io->note(sprintf('%d orphan(s) are younger than the grace period and are deleted by a later run.', \count($report->orphansKept)));
        }

        if ($report->hasProblems()) {
            $io->error('Media files are missing or damaged. Restore them from a backup.');

            return Command::FAILURE;
        }

        $io->success($dryRun ? 'Dry run finished; nothing was changed.' : 'The media files match the media library.');

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $items
     */
    private function list(SymfonyStyle $io, string $title, array $items, string $style): void
    {
        if ([] === $items) {
            return;
        }

        $lines = array_map(static fn (string $item): string => '- '.$item, \array_slice($items, 0, self::LIST_LIMIT));
        if (\count($items) > self::LIST_LIMIT) {
            $lines[] = sprintf('… and %d more', \count($items) - self::LIST_LIMIT);
        }

        'error' === $style ? $io->error([$title, ...$lines]) : $io->note([$title, ...$lines]);
    }
}
