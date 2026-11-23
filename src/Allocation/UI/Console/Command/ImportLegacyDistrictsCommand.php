<?php

declare(strict_types=1);

namespace App\Allocation\UI\Console\Command;

use App\Allocation\Application\Geo\LegacyDistrictTakeover;
use App\Allocation\Application\Geo\LegacyDistrictTakeoverOutcome;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:geo:import-legacy-districts',
    description: 'One-time offline takeover of the legacy district geography into the database (--verify checks it).',
)]
final readonly class ImportLegacyDistrictsCommand
{
    public function __construct(
        private LegacyDistrictTakeover $takeover,
        #[Autowire(param: 'app.demo_mode')]
        private bool $demoMode,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Compare the stored data with the reference data and mark the takeover as complete')]
        bool $verify = false,
    ): int {
        if ($this->demoMode) {
            $io->note('Demo environment: skipped, the demo build creates its own districts.');

            return Command::SUCCESS;
        }

        $report = $verify ? $this->takeover->verify() : $this->takeover->import();

        if ([] !== $report->problems) {
            $io->listing($report->problems);
        }

        switch ($report->outcome) {
            case LegacyDistrictTakeoverOutcome::AlreadyComplete:
                $io->success('The takeover is already complete.');

                return Command::SUCCESS;
            case LegacyDistrictTakeoverOutcome::NothingToTakeOver:
                $io->note('No legacy dispatch area exists here: nothing to take over.');

                return Command::SUCCESS;
            case LegacyDistrictTakeoverOutcome::Failed:
                $io->error($verify ? 'Verification failed; the maps must not be switched.' : 'The takeover did not run.');

                return Command::FAILURE;
            case LegacyDistrictTakeoverOutcome::Done:
                $io->success($verify
                    ? 'All legacy dispatch areas match their reference. The takeover is complete.'
                    : sprintf('Districts created: %d, assigned: %d, geometries stored: %d.', $report->created, $report->assigned, $report->geometriesStored));

                return Command::SUCCESS;
        }
    }
}
