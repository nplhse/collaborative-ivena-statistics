<?php

declare(strict_types=1);

namespace App\Feedback\UI\Console\Command;

use App\Feedback\Application\FeedbackReclassification;
use App\Feedback\Application\FeedbackReclassifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Moves existing feedback that the current spam rules would quarantine into quarantine (issue #964).
 * A dry run by default; the output lists ids, dates, scores and reason codes, never message content.
 */
#[AsCommand(
    name: 'app:feedback:reclassify',
    description: 'Check existing feedback against the spam rules and move matches into quarantine (dry run unless --apply).',
)]
final readonly class ReclassifyFeedbackCommand
{
    public function __construct(
        private FeedbackReclassifier $reclassifier,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Move the listed entries into quarantine and fill in missing message fingerprints')]
        bool $apply = false,
    ): int {
        $results = $this->reclassifier->reclassify($apply);

        if ([] === $results) {
            $io->success('No normal feedback matches the spam rules.');

            return Command::SUCCESS;
        }

        $io->table(
            ['ID', 'Created', 'Score', 'Reasons'],
            array_map(static fn (FeedbackReclassification $result): array => [
                $result->feedbackId,
                $result->createdAt->format('Y-m-d H:i'),
                $result->score,
                implode(', ', $result->reasons),
            ], $results),
        );

        $count = \count($results);
        $noun = 1 === $count ? 'entry' : 'entries';
        if ($apply) {
            $io->success(\sprintf('Moved %d %s into quarantine.', $count, $noun));
        } else {
            $io->note(\sprintf('Dry run: %d %s would move into quarantine. Review the list in the admin area, then run again with --apply.', $count, $noun));
        }

        return Command::SUCCESS;
    }
}
