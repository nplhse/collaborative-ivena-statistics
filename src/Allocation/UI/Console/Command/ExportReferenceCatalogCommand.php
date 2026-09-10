<?php

declare(strict_types=1);

namespace App\Allocation\UI\Console\Command;

use App\Allocation\Application\ReferenceCatalog\InvalidReferenceCatalogTypeException;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogExporter;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogType;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogWriter;
use App\Allocation\UI\Console\Input\ExportReferenceCatalogInput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;

#[AsCommand(
    name: 'app:reference:export',
    description: 'Export the current allocation reference catalog from the database into one YAML file.',
)]
final readonly class ExportReferenceCatalogCommand
{
    public function __construct(
        private ReferenceCatalogExporter $exporter,
        private ReferenceCatalogWriter $writer,
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

        $output = $this->resolvePath($input->output);
        $document = $this->exporter->export($types);
        $this->writer->write($output, $document);

        $io->success(sprintf('Wrote reference catalog to %s.', $output));

        return Command::SUCCESS;
    }

    private function resolvePath(string $path): string
    {
        return Path::isAbsolute($path) ? $path : Path::join($this->projectDir, $path);
    }
}
