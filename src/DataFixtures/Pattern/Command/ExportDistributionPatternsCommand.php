<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Command;

use App\DataFixtures\Pattern\Application\PatternExporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:fixtures:export-patterns',
    description: 'Export distribution patterns from allocation_stats_projection into fixtures/patterns/',
)]
final class ExportDistributionPatternsCommand extends Command
{
    public function __construct(
        private readonly PatternExporter $exporter,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('min-sample-size', null, InputOption::VALUE_REQUIRED, 'Minimum rows required per segment', '100');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ui = new SymfonyStyle($input, $output);
        $minSampleSize = (int) $input->getOption('min-sample-size');

        $exported = $this->exporter->exportAll($minSampleSize);
        if ([] === $exported) {
            $ui->warning(sprintf('No patterns exported (minimum sample size: %d).', $minSampleSize));

            return Command::FAILURE;
        }

        $ui->success(sprintf('Exported patterns: %s', implode(', ', $exported)));

        return Command::SUCCESS;
    }
}
