<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Command;

use App\DataFixtures\Pattern\Application\PatternValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:fixtures:validate-patterns',
    description: 'Validate committed distribution pattern YAML files',
)]
final readonly class ValidateDistributionPatternsCommand
{
    public function __construct(
        private PatternValidator $validator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Minimum sample_size per pattern', name: 'min-sample-size')]
        int $minSampleSize = 100,
    ): int {
        $errors = $this->validator->validateAll($minSampleSize);

        if ([] === $errors) {
            $io->success('All distribution patterns are valid.');

            return Command::SUCCESS;
        }

        $io->error('Pattern validation failed:');
        $io->listing($errors);

        return Command::FAILURE;
    }
}
