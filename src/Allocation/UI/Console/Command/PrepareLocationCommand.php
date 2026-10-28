<?php

declare(strict_types=1);

namespace App\Allocation\UI\Console\Command;

use App\Allocation\Application\Hospital\PreparedLocation\PreparedLocationPreparer;
use App\Allocation\UI\Console\Input\PrepareLocationCommandInput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:geo:prepare-location',
    description: 'Prepare a location (coordinates and isochrones) for offline use, e.g. in the demo (default: dry-run preview).',
)]
final readonly class PrepareLocationCommand
{
    public function __construct(
        private PreparedLocationPreparer $preparer,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[MapInput] PrepareLocationCommandInput $input,
    ): int {
        $apply = $input->apply;
        if ($apply && $input->dryRun) {
            $io->warning('Both --apply and --dry-run were passed; --apply takes precedence.');
        }

        $coordinateError = $input->coordinateError();
        if (null !== $coordinateError) {
            $io->error($coordinateError);

            return Command::FAILURE;
        }

        $io->title('Prepare location');
        $report = $this->preparer->run($input->toRequest(), $apply, $input->force, $input->delayMs);

        if ([] !== $report->steps) {
            $io->table(['Step', 'Detail', 'Status'], $report->steps);
            $io->text(sprintf(
                'OpenRouteService requests: %d required%s',
                $report->requestsRequired,
                $report->dryRun ? '' : sprintf(', %d executed', $report->requestsExecuted),
            ));
        }

        if (!$report->success) {
            $io->error($report->error ?? 'Preparing the location failed.');
            if ($report->rateLimited) {
                $io->note('Nothing was stored. Wait for the OpenRouteService limit to reset and re-run the same command.');
            }

            return Command::FAILURE;
        }

        if ($report->dryRun) {
            $io->success('Dry run finished. Re-run with --apply to store the location.');

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Stored prepared location "%s". Apply it offline with: app:geo:apply-prepared-location %s --hospital-id=<id> --apply',
            $report->location->slug ?? '',
            $report->location->slug ?? '',
        ));

        return Command::SUCCESS;
    }
}
