<?php

declare(strict_types=1);

namespace App\Feedback\UI\Console\Command;

use App\Feedback\Application\Contract\FeedbackQuarantineSummaryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sends the feedback quarantine summary now, like the weekly scheduler job (issue #964).
 */
#[AsCommand(
    name: 'app:feedback:quarantine-summary',
    description: 'Send the summary of feedback quarantined since the last summary now.',
)]
final readonly class SendFeedbackQuarantineSummaryCommand
{
    public function __construct(
        private FeedbackQuarantineSummaryInterface $summary,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Only show how many entries the summary would report')]
        bool $dryRun = false,
    ): int {
        if ($dryRun) {
            $io->writeln(\sprintf('Entries waiting for the summary: %d', $this->summary->countPending()));

            return Command::SUCCESS;
        }

        $reported = $this->summary->send();
        if (0 === $reported) {
            $io->success('Nothing new in quarantine; no summary sent.');
        } else {
            $io->success(\sprintf('Summary sent for %d quarantined %s.', $reported, 1 === $reported ? 'entry' : 'entries'));
        }

        return Command::SUCCESS;
    }
}
