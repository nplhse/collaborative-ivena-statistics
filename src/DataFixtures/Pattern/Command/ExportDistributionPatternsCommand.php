<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Command;

use App\DataFixtures\Pattern\Application\PatternExporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:fixtures:export-patterns',
    description: 'Export distribution patterns from allocation_stats_projection into fixtures/patterns/',
)]
final readonly class ExportDistributionPatternsCommand
{
    public function __construct(
        private PatternExporter $exporter,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Minimum rows required per segment', name: 'min-sample-size')]
        int $minSampleSize = 100,
    ): int {
        $exported = $this->exporter->exportAll($minSampleSize);
        if ([] === $exported) {
            $io->warning(sprintf('No patterns exported (minimum sample size: %d).', $minSampleSize));

            return Command::FAILURE;
        }

        $io->success(sprintf('Exported patterns: %s', implode(', ', $exported)));

        return Command::SUCCESS;
    }
}
