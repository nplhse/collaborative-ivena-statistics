<?php

declare(strict_types=1);

namespace App\Encounter\UI\Console\Command;

use App\Encounter\Application\Contract\EncounterMatcherInterface;
use App\Encounter\Application\Matching\MatchScope;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs the matcher for the allocations of one hospital in a period (ADR 030, #1053), for example after
 * changing the thresholds or for synthetic data loaded without import. Existing links and manual
 * decisions stay; only open proposals of the period are recomputed. Prints counts only.
 */
#[AsCommand(
    name: 'app:encounter:match',
    description: 'Link ED visits with the IVENA allocations of a hospital and period (production matcher); existing links and decisions stay.',
)]
final readonly class MatchEncountersCommand
{
    public function __construct(
        private EncounterMatcherInterface $matcher,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Hospital id')]
        int $hospital = 0,
        #[Option(description: 'First arrival day of the allocations (YYYY-MM-DD)')]
        string $from = '',
        #[Option(description: 'Last arrival day of the allocations (YYYY-MM-DD), default: same as --from')]
        string $to = '',
    ): int {
        $first = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $last = '' === $to ? $first : \DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if ($hospital < 1 || !$first instanceof \DateTimeImmutable || !$last instanceof \DateTimeImmutable || $last < $first) {
            $io->error('Pass --hospital=<id> and --from=YYYY-MM-DD, optionally --to=YYYY-MM-DD not before --from.');

            return Command::INVALID;
        }

        $result = $this->matcher->run(MatchScope::forPeriod($hospital, $first, $last));

        $io->table(['Allocations', 'Candidate visits', 'New automatic links', 'Open proposals', 'Of these in conflict'], [[
            $result->allocations,
            $result->encounters,
            $result->autoAccepted,
            $result->proposed,
            $result->conflicts,
        ]]);
        $io->success(sprintf('Matched hospital %d from %s to %s.', $hospital, $first->format('Y-m-d'), $last->format('Y-m-d')));

        return Command::SUCCESS;
    }
}
