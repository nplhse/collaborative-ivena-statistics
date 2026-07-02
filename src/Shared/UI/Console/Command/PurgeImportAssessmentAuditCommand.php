<?php

declare(strict_types=1);

namespace App\Shared\UI\Console\Command;

use App\Shared\Infrastructure\Audit\Query\ImportAssessmentAuditPurgeQuery;
use App\Shared\UI\Console\Input\PurgeImportAssessmentAuditInput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:audit:purge-import-assessments',
    description: 'Remove import-generated Assessment create entries from audit_log (default: dry-run preview).',
)]
final readonly class PurgeImportAssessmentAuditCommand
{
    public function __construct(
        private ImportAssessmentAuditPurgeQuery $purgeQuery,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[MapInput] PurgeImportAssessmentAuditInput $input,
    ): int {
        $io->title('Purge import-generated Assessment audit entries');

        $dryRun = !$input->execute;
        if ($input->execute && $input->dryRun) {
            $io->warning('Both --execute and --dry-run were passed; --execute takes precedence.');
            $dryRun = false;
        }

        $count = $this->purgeQuery->countCandidates();
        $range = $this->purgeQuery->fetchOccurredAtRange();

        $io->section('Candidates');
        $io->writeln(sprintf('Matching audit entries: <info>%d</info>', $count));

        if (null !== $range) {
            $io->writeln(sprintf(
                'Occurred at range: <info>%s</info> — <info>%s</info>',
                $range['min']->format('Y-m-d H:i:s'),
                $range['max']->format('Y-m-d H:i:s'),
            ));
        }

        if (0 === $count) {
            $io->success('No import-generated Assessment create audit entries found.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->note('Dry run: no audit_log rows were deleted.');
            $io->success('Re-run with --execute to delete the entries listed above.');

            return Command::SUCCESS;
        }

        $deleted = $this->purgeQuery->deleteCandidates();
        $io->success(sprintf('Deleted %d import-generated Assessment create audit entr%s.', $deleted, 1 === $deleted ? 'y' : 'ies'));

        return Command::SUCCESS;
    }
}
