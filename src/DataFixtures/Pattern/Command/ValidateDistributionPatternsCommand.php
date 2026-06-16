<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Command;

use App\DataFixtures\Pattern\Application\PatternValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:fixtures:validate-patterns',
    description: 'Validate committed distribution pattern YAML files',
)]
final class ValidateDistributionPatternsCommand extends Command
{
    public function __construct(
        private readonly PatternValidator $validator,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('min-sample-size', null, InputOption::VALUE_REQUIRED, 'Minimum sample_size per pattern', '100');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ui = new SymfonyStyle($input, $output);
        $minSampleSize = (int) $input->getOption('min-sample-size');
        $errors = $this->validator->validateAll($minSampleSize);

        if ([] === $errors) {
            $ui->success('All distribution patterns are valid.');

            return Command::SUCCESS;
        }

        $ui->error('Pattern validation failed:');
        $ui->listing($errors);

        return Command::FAILURE;
    }
}
