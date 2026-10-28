<?php

declare(strict_types=1);

namespace App\Allocation\UI\Console\Command;

use App\Allocation\Application\Contracts\PreparedLocationStoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:geo:list-prepared-locations',
    description: 'List locations prepared for offline use.',
)]
final readonly class ListPreparedLocationsCommand
{
    public function __construct(
        private PreparedLocationStoreInterface $store,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $locations = $this->store->all();
        if ([] === $locations) {
            $io->note('No prepared locations yet. Create one online with app:geo:prepare-location.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($locations as $location) {
            $rows[] = [
                $location->slug,
                $location->label,
                $location->address?->display() ?? '—',
                sprintf('%s, %s', $location->latitude, $location->longitude),
                (string) \count($location->isochrones['features']),
                $location->preparedAt->format('Y-m-d H:i'),
            ];
        }

        $io->table(['Slug', 'Label', 'Address', 'Coordinates', 'Bands', 'Prepared'], $rows);

        return Command::SUCCESS;
    }
}
