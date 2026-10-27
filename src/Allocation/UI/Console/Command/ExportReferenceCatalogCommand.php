<?php

declare(strict_types=1);

namespace App\Allocation\UI\Console\Command;

use App\Allocation\Application\ReferenceCatalog\InvalidReferenceCatalogTypeException;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackageReader;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackageWriter;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogExporter;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogType;
use App\Allocation\UI\Console\Input\ExportReferenceCatalogInput;
use App\Allocation\UI\Console\ReferenceCatalogViolationTable;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;

#[AsCommand(
    name: 'app:reference:export',
    description: 'Export the reference catalog from the database into one JSON package (ADR 020).',
)]
final readonly class ExportReferenceCatalogCommand
{
    public function __construct(
        private ReferenceCatalogExporter $exporter,
        private ReferenceCatalogPackageWriter $writer,
        private ReferenceCatalogPackageReader $reader,
        private ClockInterface $clock,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[MapInput] ExportReferenceCatalogInput $input,
    ): int {
        try {
            $types = ReferenceCatalogType::parseList($input->types);
        } catch (InvalidReferenceCatalogTypeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $package = $this->exporter->export($types, $input->notes);

        // Read the package back before writing it, so a file that cannot be imported is never written.
        $result = $this->reader->readJson($this->writer->encode($package));
        if (!$result->isValid()) {
            $io->error(sprintf('The database contains reference data that does not form a valid package (%d problem(s)). Nothing was written.', \count($result->violations)));
            ReferenceCatalogViolationTable::render($io, $result->violations);

            return Command::FAILURE;
        }

        $output = $this->resolvePath($input->output ?? sprintf('var/reference/catalog-%s.json', $this->clock->now()->format('Ymd-His')));
        $this->writer->write($output, $package);

        $io->table(['Type', 'Entries'], array_map(
            static fn (string $type, int $count): array => [$type, (string) $count],
            array_keys($package->meta->counts),
            array_values($package->meta->counts),
        ));
        $io->success(sprintf('Wrote reference catalog package to %s.', $output));

        return Command::SUCCESS;
    }

    private function resolvePath(string $path): string
    {
        return Path::isAbsolute($path) ? $path : Path::join($this->projectDir, $path);
    }
}
