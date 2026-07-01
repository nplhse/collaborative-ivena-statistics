<?php

declare(strict_types=1);

namespace App\Content\UI\Console\Command;

use App\Content\Application\Media\MediaDimensionsBackfillService;
use App\Content\Application\Page\PageImageContentAnalyzer;
use App\Content\Application\Page\PageImageContentMigrationService;
use App\Content\UI\Console\Input\AnalyzePageImagesInput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:content:analyze-page-images',
    description: 'Analyze page image references, backfill media dimensions, and optionally migrate layout sizes.',
)]
final readonly class AnalyzePageImagesCommand
{
    public function __construct(
        private PageImageContentAnalyzer $analyzer,
        private MediaDimensionsBackfillService $dimensionsBackfillService,
        private PageImageContentMigrationService $migrationService,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[MapInput] AnalyzePageImagesInput $input,
    ): int {
        $hasWriteOperations = $input->backfillDimensions || $input->migrateSize || $input->fixRichtextSnippets;
        $dryRun = $hasWriteOperations && $input->dryRun;
        $pageId = $this->parsePageId($input->pageId);

        $io->title('Analyze page images');

        if ($dryRun) {
            $io->note('Dry run: no database or content changes will be written. Re-run without --dry-run to persist changes.');
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

        if ($input->backfillDimensions) {
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

        if ($input->migrateSize) {
            $result = $this->migrationService->migrateSizes($dryRun, $pageId);
            $io->section('Image block size migration');
            $io->writeln(sprintf('Updated image blocks: %d', $result->updatedBlocks));
            $io->writeln(sprintf('Affected pages: %d', $result->updatedPages));
        }

        if ($input->fixRichtextSnippets) {
            $result = $this->migrationService->fixRichtextSnippets($dryRun, $pageId);
            $io->section('Richtext snippet migration');
            $io->writeln(sprintf('Updated HTML blocks: %d', $result->updatedBlocks));
            $io->writeln(sprintf('Affected pages: %d', $result->updatedPages));
        }

        if ($dryRun) {
            $io->success('Dry run finished. Re-run without --dry-run to persist changes.');
        } else {
            $io->success('Analysis finished.');
        }

        return Command::SUCCESS;
    }

    private function parsePageId(?string $value): ?int
    {
        if (null === $value || !ctype_digit($value)) {
            return null;
        }

        $pageId = (int) $value;

        return $pageId > 0 ? $pageId : null;
    }
}
