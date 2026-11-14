<?php

declare(strict_types=1);

namespace App\Encounter\UI\Console\Command;

use App\Encounter\Application\Curation\CurationExporter;
use App\Encounter\Application\Curation\Package\CurationPackageReader;
use App\Encounter\Application\Curation\Package\CurationPackageWriter;
use App\Encounter\UI\Console\CurationViolationTable;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;

/**
 * Exports the curated MTS catalog and the plausibility problem areas into one JSON package (#1198), to carry a
 * reviewed state to another instance with app:encounter:curation:import.
 */
#[AsCommand(
    name: 'app:encounter:curation:export',
    description: 'Export the curated MTS catalog and the plausibility problem areas into one JSON package (#1198).',
)]
final readonly class ExportCurationPackageCommand
{
    public function __construct(
        private CurationExporter $exporter,
        private CurationPackageWriter $writer,
        private CurationPackageReader $reader,
        private ClockInterface $clock,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Path of the package file; default: var/encounter/curation-<Ymd-His>.json')]
        string $output = '',
        #[Option(description: 'Free-text note stored in meta.notes')]
        string $notes = '',
    ): int {
        $export = $this->exporter->export('' === trim($notes) ? null : trim($notes));
        $package = $export->package;

        // Read the package back before writing it, so a file that cannot be imported is never written.
        $result = $this->reader->readJson($this->writer->encode($package));
        if (!$result->isValid()) {
            $io->error(\sprintf('The curated data does not form a valid package (%d problem(s)). Nothing was written.', \count($result->violations)));
            CurationViolationTable::render($io, $result->violations);

            return Command::FAILURE;
        }

        $path = '' === trim($output) ? \sprintf('var/encounter/curation-%s.json', $this->clock->now()->format('Ymd-His')) : trim($output);
        $path = Path::isAbsolute($path) ? $path : Path::join($this->projectDir, $path);
        $this->writer->write($path, $package);

        $io->table(['Section', 'Entries'], array_map(
            static fn (string $section, int $count): array => [$section, (string) $count],
            array_keys($package->meta->counts),
            array_values($package->meta->counts),
        ));

        $omitted = array_filter($export->omitted);
        if ([] !== $omitted) {
            $io->note(\sprintf(
                'Not curated, so not exported: %s.',
                implode(', ', array_map(static fn (string $kind, int $count): string => \sprintf('%d %s', $count, $kind), array_keys($omitted), array_values($omitted))),
            ));
        }

        $io->success(\sprintf('Wrote the curation package to %s (checksum %s).', $path, $package->meta->checksum->value));

        return Command::SUCCESS;
    }
}
