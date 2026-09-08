<?php

declare(strict_types=1);

namespace App\Allocation\UI\Console\Command;

use App\Allocation\Application\Hospital\HospitalIsochroneFetchService;
use App\Allocation\UI\Console\Input\FetchHospitalIsochronesInput;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:allocation:fetch-hospital-isochrones',
    description: 'Fetch destination isochrones for all hospitals in a federal state and store them as GeoJSON (default: dry-run preview).',
)]
final readonly class FetchHospitalIsochronesCommand
{
    public function __construct(
        private HospitalIsochroneFetchService $fetchService,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'ID of the federal state', name: 'stateId')]
        int $stateId,
        #[MapInput] FetchHospitalIsochronesInput $input,
    ): int {
        $apply = $input->apply;
        if ($apply && $input->dryRun) {
            $io->warning('Both --apply and --dry-run were passed; --apply takes precedence.');
        }

        $io->title('Fetch hospital isochrones');

        $report = $this->fetchService->run($stateId, $apply, $input->force, $input->delayMs);
        if (!$report->success) {
            $io->error($report->error ?? 'Isochrone fetch failed.');

            return Command::FAILURE;
        }

        if ($report->dryRun) {
            $io->note('Dry run: no OpenRouteService calls and no files will be written. Re-run with --apply to fetch missing files.');
        } else {
            $io->warning('Apply mode: missing isochrone files will be fetched from OpenRouteService.');
        }

        $io->section('Hospitals');
        $io->table(['Hospital', 'Coordinates', 'Status'], $report->rows);

        $io->section('Summary');
        $io->table(
            ['Metric', 'Count'],
            [
                ['Hospitals inspected', (string) $report->inspected],
                ['Missing coordinates', (string) $report->missingCoords],
                ['Existing files skipped', (string) $report->skipped],
                [$report->dryRun ? 'Would fetch' : 'Fetched', (string) ($report->dryRun ? $report->toFetch : $report->written)],
                ...($report->dryRun ? [] : [['Failed', (string) $report->failed]]),
            ],
        );

        if ($report->dryRun) {
            $io->success('Dry run finished. Re-run with --apply to fetch missing isochrones.');
        } elseif ($report->failed > 0) {
            $io->warning(sprintf('Wrote %d file(s); %d request(s) failed.', $report->written, $report->failed));
        } else {
            $io->success(sprintf('Wrote %d isochrone file(s).', $report->written));
        }

        return Command::SUCCESS;
    }
}
