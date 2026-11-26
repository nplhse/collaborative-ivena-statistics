<?php

declare(strict_types=1);

namespace App\Encounter\UI\Console\Command;

use App\Encounter\Application\Contract\EncounterCleanupInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Removes encounters that no clinical record references any more (#1300), as the daily job does.
 *
 * @psalm-suppress UnusedClass Registered via #[AsCommand].
 */
#[AsCommand(
    name: 'app:encounter:clean-up',
    description: 'Remove encounters without clinical records (also when an IVENA allocation is assigned; the allocation stays).',
)]
final readonly class CleanUpEncountersCommand
{
    public function __construct(
        private EncounterCleanupInterface $cleanup,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Hospital id; all hospitals with encounters when left out')]
        int $hospital = 0,
    ): int {
        if ($hospital < 0) {
            $io->error('Pass --hospital=<id> or leave it out.');

            return Command::INVALID;
        }

        $result = $this->cleanup->run($hospital > 0 ? $hospital : null);

        $io->table(['Hospitals', 'Removed', 'Of these with an IVENA assignment', 'Hospitals skipped'], [[$result->hospitals, $result->removed, $result->removedWithAssignment, $result->skippedHospitals]]);
        $io->success('Removed the encounters without clinical records.');

        return Command::SUCCESS;
    }
}
