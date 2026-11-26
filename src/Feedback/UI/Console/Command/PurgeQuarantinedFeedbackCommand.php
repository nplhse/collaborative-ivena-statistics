<?php

declare(strict_types=1);

namespace App\Feedback\UI\Console\Command;

use App\Feedback\Application\Contract\FeedbackQuarantinePurgeInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes quarantined feedback past its retention now, like the daily scheduler job (issue #964).
 * A dry run by default.
 */
#[AsCommand(
    name: 'app:feedback:purge-quarantine',
    description: 'Delete quarantined feedback past its retention (dry run unless --apply).',
)]
final readonly class PurgeQuarantinedFeedbackCommand
{
    public function __construct(
        private FeedbackQuarantinePurgeInterface $purge,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Delete the entries instead of only counting them')]
        bool $apply = false,
    ): int {
        if (!$apply) {
            $io->writeln(\sprintf('Quarantined entries past their retention: %d (dry run, nothing deleted)', $this->purge->countExpired()));

            return Command::SUCCESS;
        }

        $deleted = $this->purge->purge();
        $io->success(\sprintf('Deleted %d quarantined %s.', $deleted, 1 === $deleted ? 'entry' : 'entries'));

        return Command::SUCCESS;
    }
}
