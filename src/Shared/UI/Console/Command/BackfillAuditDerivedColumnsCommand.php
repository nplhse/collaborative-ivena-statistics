<?php

declare(strict_types=1);

namespace App\Shared\UI\Console\Command;

use App\Shared\Infrastructure\Audit\Query\AuditDerivedColumnsBackfillQuery;
use App\Shared\UI\Console\Input\BackfillAuditDerivedColumnsInput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:audit:backfill-derived',
    description: 'Copy intent and outcome of older audit entries from their stored metadata (default: preview).',
)]
final readonly class BackfillAuditDerivedColumnsCommand
{
    public function __construct(
        private AuditDerivedColumnsBackfillQuery $query,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[MapInput] BackfillAuditDerivedColumnsInput $input,
    ): int {
        $io->title('Backfill audit log intent and outcome');

        $missingIntent = $this->query->countMissingIntent();
        $missingOutcome = $this->query->countMissingOutcome();

        if (!$input->apply) {
            $io->note('Preview: nothing is written. Run again with --apply to copy the values.');
            $io->table(['Column', 'Entries to fill'], [
                ['intent', (string) $missingIntent],
                ['outcome', (string) $missingOutcome],
            ]);

            return Command::SUCCESS;
        }

        $intent = $this->query->fillIntent();
        $outcome = $this->query->fillOutcome();

        $io->table(['Column', 'Entries filled'], [
            ['intent', (string) $intent],
            ['outcome', (string) $outcome],
        ]);
        $io->success('Backfill finished.');

        return Command::SUCCESS;
    }
}
