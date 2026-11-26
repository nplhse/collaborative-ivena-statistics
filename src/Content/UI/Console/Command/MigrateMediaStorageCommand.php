<?php

declare(strict_types=1);

namespace App\Content\UI\Console\Command;

use App\Content\Application\Contract\MediaStorageMigratorInterface;
use App\Content\Application\Media\MediaMaintenanceException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Moves media items from the old upload directory into the media storage (ADR 039). Deployer runs it before the
 * switch to a new release; it does nothing when every media item has moved. Exits with 1 only when a file could not
 * be moved: media items whose file is missing are listed but do not stop a deploy.
 */
#[AsCommand(
    name: 'app:media:migrate-storage',
    description: 'Move media files from the old upload directory into the media storage, with checksum verification.',
)]
final readonly class MigrateMediaStorageCommand
{
    private const int LIST_LIMIT = 20;

    public function __construct(
        private MediaStorageMigratorInterface $migrator,
        #[Autowire(param: 'app.media_legacy_dir')]
        private string $defaultLegacyDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Only show what would be moved', name: 'dry-run')]
        bool $dryRun = false,
        #[Option(description: 'Directory of the old media files (default: the old upload directory of this installation)', name: 'legacy-dir')]
        ?string $legacyDir = null,
    ): int {
        $legacyDir ??= $this->defaultLegacyDir;
        $io->title('Move media files into the media storage');
        $io->definitionList(['Old directory' => $legacyDir], ['Mode' => $dryRun ? 'dry run, nothing is changed' : 'move']);

        try {
            $result = $this->migrator->migrate($legacyDir, $dryRun, static function (string $filename, string $outcome) use ($io): void {
                if ($io->isVerbose() || 'moved' !== $outcome) {
                    $io->writeln(sprintf(' %s %s', $outcome, $filename), OutputInterface::VERBOSITY_NORMAL);
                }
            });
        } catch (MediaMaintenanceException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->table(['', 'Media items'], [
            [$dryRun ? 'Would move' : 'Moved', sprintf('%d (%s)', $result->moved, Helper::formatMemory($result->movedBytes))],
            ['File missing', (string) \count($result->missing)],
            ['Failed', (string) \count($result->failed)],
            ['Files without media item', (string) \count($result->unlinked)],
        ]);

        if ([] !== $result->missing) {
            $io->warning(['These media items have no file in the old directory; their URLs answer 404:', ...$this->listed($result->missing)]);
        }

        if ([] !== $result->unlinked) {
            $io->note(['These files in the old directory belong to no media item and were not moved:', ...$this->listed($result->unlinked)]);
        }

        if ($result->hasFailures()) {
            $io->error(['These media items could not be moved and keep their old file:', ...$this->listed($result->failed), 'Fix the cause and run the command again; moved items are skipped.']);

            return Command::FAILURE;
        }

        $io->success(match (true) {
            0 === $result->moved && [] === $result->missing => 'Every media item is in the media storage.',
            0 === $result->moved => sprintf('No file left to move, but %d media item(s) have no file and are not in the media storage.', \count($result->missing)),
            $dryRun => 'Dry run finished. Run the command without --dry-run to move the files.',
            default => sprintf('%d media item(s) moved. The old directory is unchanged and can be removed after v0.4.', $result->moved),
        });

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $items
     *
     * @return list<string>
     */
    private function listed(array $items): array
    {
        $listed = array_map(static fn (string $item): string => '- '.$item, \array_slice($items, 0, self::LIST_LIMIT));
        if (\count($items) > self::LIST_LIMIT) {
            $listed[] = sprintf('… and %d more', \count($items) - self::LIST_LIMIT);
        }

        return $listed;
    }
}
