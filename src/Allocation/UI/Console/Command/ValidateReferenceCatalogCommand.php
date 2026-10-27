<?php

declare(strict_types=1);

namespace App\Allocation\UI\Console\Command;

use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackageReader;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogType;
use App\Allocation\UI\Console\Input\ValidateReferenceCatalogInput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;

#[AsCommand(
    name: 'app:reference:validate',
    description: 'Check a reference catalog package (JSON) without importing it.',
)]
final readonly class ValidateReferenceCatalogCommand
{
    public function __construct(
        private ReferenceCatalogPackageReader $reader,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[MapInput] ValidateReferenceCatalogInput $input,
    ): int {
        $source = Path::isAbsolute($input->source) ? $input->source : Path::join($this->projectDir, $input->source);
        $result = $this->reader->read($source);

        if (!$result->isValid()) {
            $io->error(sprintf('%s is not a valid reference catalog package (%d problem(s)).', $source, \count($result->violations)));
            $io->table(
                ['Path', 'Problem', 'Value'],
                array_map(
                    static fn (\App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogViolation $violation): array => ['' === $violation->path ? '(file)' : $violation->path, $violation->message, $violation->invalidValue ?? ''],
                    $result->violations,
                ),
            );

            return Command::FAILURE;
        }

        $package = $result->package;
        $io->definitionList(
            ['Version' => (string) $package->meta->version],
            ['Exported at' => $package->meta->exportedAt],
            ['Source' => $package->meta->sourceEnvironment ?? '—'],
            ['Complete' => $package->meta->complete ? 'yes' : 'no'],
        );

        $counts = $package->counts();
        $rows = [];
        foreach (ReferenceCatalogType::importOrder() as $type) {
            if (\in_array($type->value, $package->meta->types, true)) {
                $rows[] = [$type->value, (string) $counts[$type->value]];
            }
        }
        $io->table(['Type', 'Entries'], $rows);
        $io->success(sprintf('%s is a valid reference catalog package.', $source));

        return Command::SUCCESS;
    }
}
