<?php

declare(strict_types=1);

namespace App\Encounter\UI\Console\Command;

use App\Encounter\Application\Assignment\AssignmentRetryResult;
use App\Encounter\Application\Contract\AssignmentRetrierInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Regular retry of records kept without encounter (#1307), for operators and tests: records without candidate
 * and records left unassigned for now. Open conflicts wait for review.
 *
 * @psalm-suppress UnusedClass Registered via #[AsCommand].
 */
#[AsCommand(
    name: 'app:encounter:retry-assignments',
    description: 'Retry records of further encounter data that wait without encounter (no candidate, left unassigned for now).',
)]
final readonly class RetryEncounterAssignmentsCommand
{
    public function __construct(
        private AssignmentRetrierInterface $retrier,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Hospital id; all hospitals with waiting records when left out')]
        int $hospital = 0,
    ): int {
        if ($hospital < 0) {
            $io->error('Pass --hospital=<id> or leave it out.');

            return Command::INVALID;
        }

        $hospitals = $hospital > 0 ? [$hospital] : $this->retrier->hospitalsWithRetryableRecords();
        $result = new AssignmentRetryResult();
        foreach ($hospitals as $id) {
            $result = $result->plus($this->retrier->retryHospital($id));
        }

        $io->table(['Hospitals', 'Attached', 'Reopened', 'Unchanged', 'Skipped'], [[\count($hospitals), $result->attached, $result->reopened, $result->unchanged, $result->skipped]]);
        $io->success('Retried the waiting encounter assignments.');

        return Command::SUCCESS;
    }
}
