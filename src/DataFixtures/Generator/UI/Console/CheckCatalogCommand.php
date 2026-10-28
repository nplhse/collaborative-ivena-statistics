<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\UI\Console;

use App\DataFixtures\Generator\Application\Catalog\CatalogCompatibility;
use App\DataFixtures\Generator\Application\Catalog\CatalogMapping;
use App\DataFixtures\Generator\Application\Catalog\CatalogMatch;
use App\DataFixtures\Generator\Application\Catalog\GeneratorCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Read-only: can the installed catalog serve the generator? Lists every missing or ambiguous name.
 */
#[AsCommand(
    name: 'app:generate:check-catalog',
    description: 'Check that the installed reference catalog has every name and PZC code the generator writes',
)]
#[When(env: 'dev')]
#[When(env: 'test')]
final readonly class CheckCatalogCommand
{
    public function __construct(
        private CatalogCompatibility $compatibility,
        private GeneratorCatalog $catalog,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Mapping file instead of FIXTURES_CATALOG_MAPPING')]
        string $mapping = '',
        #[Option(description: 'Also list every resolved name with its catalog row')]
        bool $all = false,
    ): int {
        try {
            if ('' !== $mapping) {
                $this->catalog->useMapping(CatalogMapping::fromFile($mapping));
            }
            $report = $this->compatibility->check();
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->text(null !== $report->mappingSource ? sprintf('Mapping: %s', $report->mappingSource) : 'Mapping: none');
        $rows = [];
        foreach (array_keys($report->sections) as $section) {
            $counts = $report->counts($section);
            $rows[] = [$section, $counts[CatalogMatch::RESOLVED], $counts[CatalogMatch::MISSING], $counts[CatalogMatch::AMBIGUOUS]];
        }
        $io->table(['Generator keys', 'Resolved', 'Missing', 'Ambiguous'], $rows);

        if ($all) {
            $resolved = [];
            foreach ($report->sections as $section => $matches) {
                foreach ($matches as $match) {
                    if ($match->isResolved()) {
                        $resolved[] = [$section, $match->name, (string) $match->catalogName.(null !== $match->rawName ? ' / '.$match->rawName : ''), (string) $match->via];
                    }
                }
            }
            $io->table(['Type', 'Generator key', 'Catalog row', 'Via'], $resolved);
        }

        if ([] !== $report->warnings()) {
            $io->warning($report->warnings());
        }
        if (!$report->isCompatible()) {
            $io->error(array_merge(['The catalog cannot serve the generator:'], $report->errors()));

            return Command::FAILURE;
        }

        $io->success('The catalog has every name and PZC code the generator writes.');

        return Command::SUCCESS;
    }
}
