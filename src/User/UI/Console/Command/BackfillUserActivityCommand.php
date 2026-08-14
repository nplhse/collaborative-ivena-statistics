<?php

declare(strict_types=1);

namespace App\User\UI\Console\Command;

use App\User\Infrastructure\Activity\UserActivityBackfill;
use App\User\UI\Console\Input\BackfillUserActivityInput;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:user-activity:backfill',
    description: 'Backfill the user_activity projection from historical imports, posts, comments, and hospital relations (default: dry-run).',
)]
final readonly class BackfillUserActivityCommand
{
    public function __construct(
        private UserActivityBackfill $userActivityBackfill,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[MapInput] BackfillUserActivityInput $input,
    ): int {
        $this->entityManager->clear();

        $apply = $input->apply;
        if ($apply && $input->dryRun) {
            $io->warning('Both --apply and --dry-run were passed; --apply takes precedence.');
        }
        $dryRun = !$apply;

        $io->title('Backfill user activity projection');

        if ($dryRun) {
            $io->note('Dry run: no rows will be written. Re-run with --apply to persist changes.');
        } else {
            $io->warning('Apply mode: new user_activity rows will be inserted. Existing keys are skipped.');
        }

        $report = $this->userActivityBackfill->run($apply);

        $io->section('Summary');
        $io->table(
            ['Metric', 'Count'],
            [
                ['Users inspected', (string) $report->inspected],
                ['Joined', (string) $report->joined],
                ['Import milestones', (string) $report->milestones],
                ['Published posts', (string) $report->posts],
                ['Comments', (string) $report->comments],
                ['Hospital associated', (string) $report->hospitalAssociated],
                ['Hospital owner', (string) $report->hospitalOwner],
                ['Skipped existing', (string) $report->skippedExisting],
                ['Unable to reconstruct owner', (string) $report->unableToReconstruct],
                [$dryRun ? 'Rows would be written' : 'Rows written', (string) $report->recordedTotal()],
            ],
        );

        if ($dryRun) {
            $io->success('Dry run finished. Re-run with --apply to persist changes.');
        } else {
            $io->success(sprintf('Wrote %d activity row(s).', $report->recordedTotal()));
        }

        return Command::SUCCESS;
    }
}
