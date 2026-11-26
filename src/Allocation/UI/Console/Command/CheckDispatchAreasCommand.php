<?php

declare(strict_types=1);

namespace App\Allocation\UI\Console\Command;

use App\Allocation\Infrastructure\Query\DispatchAreaShapeStatusQuery;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:geo:check-dispatch-areas',
    description: 'List dispatch areas with allocations but no complete map shape (read-only).',
)]
final readonly class CheckDispatchAreasCommand
{
    public function __construct(
        private DispatchAreaShapeStatusQuery $shapeStatus,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $rows = [];
        $otherState = [];
        foreach ($this->shapeStatus->all() as $status) {
            foreach ($status->otherStateDistricts as $district) {
                $otherState[] = sprintf('%s: %s', $status->name, $district);
            }

            if ($status->hasAllocations && !$status->hasShape()) {
                $rows[] = [$status->name, 0 === $status->districtCount ? 'no districts' : implode(', ', $status->missingDistricts)];
            }
        }

        if ([] !== $otherState) {
            $io->note(array_merge(['Districts of another state (allowed):'], $otherState));
        }

        if ([] === $rows) {
            $io->success('Every dispatch area with allocations has a complete map shape.');

            return Command::SUCCESS;
        }

        $io->table(['Dispatch area', 'Missing'], $rows);
        $io->error(sprintf('%d dispatch area(s) with allocations have no complete map shape.', \count($rows)));

        return Command::FAILURE;
    }
}
