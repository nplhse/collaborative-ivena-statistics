<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\UI\Console;

use App\DataFixtures\Generator\Application\Run\GenerationRunView;
use App\DataFixtures\Generator\Infrastructure\Run\GenerationRunStore;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:generate:run:list',
    description: 'List recent synthetic data generation runs and their status (ADR 023).',
)]
final readonly class GenerationRunListCommand
{
    public function __construct(
        private GenerationRunStore $store,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Number of runs to show')]
        int $limit = 20,
    ): int {
        $now = $this->clock->now();
        $rows = [];
        foreach ($this->store->recentIds(max(1, $limit)) as $id) {
            $run = $this->store->find($id);
            if (!$run instanceof GenerationRunView) {
                continue;
            }
            $counts = $run->hospitalCounts();
            $rows[] = [
                $run->id,
                $run->createdAt->setTimezone($now->getTimezone())->format('Y-m-d H:i'),
                $run->scope->value,
                (string) \count($run->hospitals),
                GenerationRunFormatter::status($run, $now),
                $run->stage->value,
                \sprintf('%d completed, %d failed', $counts['completed'], $counts['failed']),
            ];
        }

        if ([] === $rows) {
            $io->info('No generation runs yet.');

            return Command::SUCCESS;
        }
        $io->table(['Run', 'Created', 'Scope', 'Hospitals', 'Status', 'Stage', 'Hospital results'], $rows);

        return Command::SUCCESS;
    }
}
