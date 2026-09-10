<?php

declare(strict_types=1);

namespace App\Import\UI\Console\Command;

use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogWriter;
use App\Import\Application\ReferenceCatalog\ReferenceCatalogFromRejectsProposer;
use App\Import\Application\ReferenceCatalog\ReferenceCatalogFromRejectsResult;
use App\Import\UI\Console\Input\ProposeReferenceCatalogFromRejectsInput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

#[AsCommand(
    name: 'app:reference:propose-from-rejects',
    description: 'Propose missing catalog YAML entries from import rejects (read-only on rejects).',
)]
final readonly class ProposeReferenceCatalogFromRejectsCommand
{
    public function __construct(
        private ReferenceCatalogFromRejectsProposer $proposer,
        private ReferenceCatalogWriter $writer,
        private Filesystem $filesystem,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[MapInput] ProposeReferenceCatalogFromRejectsInput $input,
    ): int {
        $outputDir = $this->resolvePath($input->output);
        $this->filesystem->mkdir($outputDir);

        $result = $this->proposer->propose(minCount: $input->minCount);
        $this->writer->write($outputDir.'/catalog.yaml', $result->document);
        $this->filesystem->dumpFile($outputDir.'/report.md', $this->renderReport($result));
        $this->filesystem->dumpFile(
            $outputDir.'/requeue-import-ids.txt',
            implode(',', array_map(strval(...), $result->importIds)),
        );
        $this->filesystem->dumpFile($outputDir.'/requeue-imports.md', $this->renderRequeueImports($result));

        $io->title('Propose reference catalog from rejects');
        $io->writeln(sprintf('Reject rows scanned: %d', $result->rejectRowsScanned));
        $io->writeln(sprintf('Proposed catalog values: %d', $result->proposedCount));
        $io->writeln(sprintf('Dropped (already in catalog / aliases): %d', $result->droppedAsKnown));
        $io->writeln(sprintf('Dropped (junk / denylist): %d', $result->droppedAsJunk));
        $io->writeln(sprintf('Affected imports: %d', \count($result->importIds)));
        $io->success(sprintf('Wrote catalog proposal to %s', $outputDir));

        return Command::SUCCESS;
    }

    private function resolvePath(string $path): string
    {
        return Path::isAbsolute($path) ? $path : Path::join($this->projectDir, $path);
    }

    private function renderReport(ReferenceCatalogFromRejectsResult $result): string
    {
        $lines = [
            '# Reference catalog proposals from import rejects',
            '',
            sprintf('- Reject rows scanned: %d', $result->rejectRowsScanned),
            sprintf('- Proposed values: %d', $result->proposedCount),
            sprintf('- Dropped as already known: %d', $result->droppedAsKnown),
            sprintf('- Dropped as junk: %d', $result->droppedAsJunk),
            '',
            '| Type | Value | Count | Example file |',
            '|---|---|---:|---|',
        ];

        foreach ($result->entries as $entry) {
            $lines[] = sprintf(
                '| %s | %s | %d | %s |',
                $entry['type'],
                str_replace('|', '\\|', $entry['value']),
                $entry['count'],
                str_replace('|', '\\|', $entry['exampleFile']),
            );
        }

        $lines[] = '';
        $lines[] = 'Dispatch areas are written with empty `state`. Fill the Bundesland before `app:reference:import`.';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function renderRequeueImports(ReferenceCatalogFromRejectsResult $result): string
    {
        $lines = [
            '# Imports to requeue after catalog import',
            '',
            'Use `app:import:requeue-all --only-ids=` with `requeue-import-ids.txt`.',
            '',
            '| Import ID | Hospital | File | Matching reject rows |',
            '|---:|---|---|---:|',
        ];

        foreach ($result->imports as $import) {
            $lines[] = sprintf(
                '| %d | %s | %s | %d |',
                $import['importId'],
                str_replace('|', '\\|', $import['hospitalName']),
                str_replace('|', '\\|', $import['file']),
                $import['count'],
            );
        }

        $lines[] = '';

        return implode("\n", $lines);
    }
}
