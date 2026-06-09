<?php

declare(strict_types=1);

namespace App\Content\UI\Console\Command;

use App\Content\Application\Media\MediaDimensionsBackfillService;
use App\Content\Application\Page\PageImageContentAnalyzer;
use App\Content\Application\Page\PageImageContentMigrationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:content:analyze-page-images',
    description: 'Analyze page image references, backfill media dimensions, and optionally migrate layout sizes.',
)]
final class AnalyzePageImagesCommand extends Command
{
    public function __construct(
        private readonly PageImageContentAnalyzer $analyzer,
        private readonly MediaDimensionsBackfillService $dimensionsBackfillService,
        private readonly PageImageContentMigrationService $migrationService,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report findings without writing changes (default)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Apply selected write operations')
            ->addOption('backfill-dimensions', null, InputOption::VALUE_NONE, 'Backfill missing media width/height from local files')
            ->addOption('migrate-size', null, InputOption::VALUE_NONE, 'Migrate image blocks from size lg to auto when recommended')
            ->addOption('fix-richtext-snippets', null, InputOption::VALUE_NONE, 'Replace --size-lg with --size-auto in HTML snippets')
            ->addOption('page-id', null, InputOption::VALUE_REQUIRED, 'Analyze or migrate a single page');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = !$input->getOption('apply');
        $pageId = $this->parsePageId($input->getOption('page-id'));

        $io->title('Analyze page images');

        if ($dryRun) {
            $io->note('Dry run: no database or content changes will be written. Use --apply to persist changes.');
        }

        $findings = $this->analyzer->analyze($pageId);

        if ([] === $findings) {
            $io->success('No image references found in pages.');
        } else {
            $io->section(sprintf('Found %d image reference(s)', count($findings)));
            $io->table(
                ['Page', 'Block', 'Media', 'File', 'Width', 'Size', 'Status', 'Recommendation'],
                array_map(
                    static fn ($finding): array => [
                        sprintf('#%d %s', $finding->pageId, $finding->pageTitle),
                        sprintf('%d (%s)', $finding->blockIndex + 1, $finding->blockType),
                        null !== $finding->mediaId ? (string) $finding->mediaId : '-',
                        $finding->filename ?? '-',
                        null !== $finding->imageWidth ? (string) $finding->imageWidth : '-',
                        $finding->currentSize,
                        $finding->status,
                        $finding->recommendation,
                    ],
                    $findings,
                ),
            );
        }

        if ($input->getOption('backfill-dimensions')) {
            $result = $this->dimensionsBackfillService->backfill($dryRun);
            $io->section('Media dimension backfill');
            $io->table(
                ['Metric', 'Count'],
                [
                    ['Updated media records', (string) $result->updated],
                    ['Missing local files', (string) $result->missingFile],
                    ['Unknown dimensions', (string) $result->unknownDimensions],
                ],
            );
        }

        if ($input->getOption('migrate-size')) {
            $result = $this->migrationService->migrateSizes($dryRun, $pageId);
            $io->section('Image block size migration');
            $io->writeln(sprintf('Updated image blocks: %d', $result->updatedBlocks));
            $io->writeln(sprintf('Affected pages: %d', $result->updatedPages));
        }

        if ($input->getOption('fix-richtext-snippets')) {
            $result = $this->migrationService->fixRichtextSnippets($dryRun, $pageId);
            $io->section('Richtext snippet migration');
            $io->writeln(sprintf('Updated HTML blocks: %d', $result->updatedBlocks));
            $io->writeln(sprintf('Affected pages: %d', $result->updatedPages));
        }

        if ($dryRun && ($input->getOption('backfill-dimensions') || $input->getOption('migrate-size') || $input->getOption('fix-richtext-snippets'))) {
            $io->success('Dry run finished. Re-run with --apply to persist changes.');
        } else {
            $io->success('Analysis finished.');
        }

        return Command::SUCCESS;
    }

    private function parsePageId(mixed $value): ?int
    {
        if (!is_string($value) || !ctype_digit($value)) {
            return null;
        }

        $pageId = (int) $value;

        return $pageId > 0 ? $pageId : null;
    }
}
