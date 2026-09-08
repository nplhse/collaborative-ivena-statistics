<?php

declare(strict_types=1);

namespace App\Allocation\UI\Console\Command;

use App\Allocation\Application\Hospital\HospitalGeocodeService;
use App\Allocation\UI\Console\Input\GeocodeHospitalCoordinatesInput;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:hospital:geocode-coordinates',
    description: 'Geocode hospital street addresses to coordinates for a federal state (default: dry-run preview).',
)]
final readonly class GeocodeHospitalCoordinatesCommand
{
    public function __construct(
        private HospitalGeocodeService $geocodeService,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'ID of the federal state', name: 'stateId')]
        int $stateId,
        #[MapInput] GeocodeHospitalCoordinatesInput $input,
    ): int {
        $apply = $input->apply;
        if ($apply && $input->dryRun) {
            $io->warning('Both --apply and --dry-run were passed; --apply takes precedence.');
        }

        $io->title('Geocode hospital coordinates');

        $report = $this->geocodeService->run($stateId, $apply, $input->force, $input->delayMs);
        if (!$report->success) {
            $io->error($report->error ?? 'Hospital geocoding failed.');

            return Command::FAILURE;
        }

        if ($report->dryRun) {
            $io->note('Dry run: no OpenRouteService calls and no coordinates will be written. Existing coordinates are skipped unless --force is passed. Re-run with --apply to geocode.');
        } else {
            $io->warning('Apply mode: missing coordinates will be written from OpenRouteService. Use --force to overwrite existing values (including city/postal-code centroids).');
        }

        $io->section('Hospitals');
        $io->table(['Hospital', 'Address', 'Previous', 'New', 'Status'], $report->rows);

        $io->section('Summary');
        $io->table(
            ['Metric', 'Count'],
            [
                ['Hospitals inspected', (string) $report->inspected],
                ['Existing coordinates skipped', (string) $report->skipped],
                ['Missing address', (string) $report->missingAddress],
                [$report->dryRun ? 'Would geocode' : 'Geocoded', (string) ($report->dryRun ? $report->toGeocode : $report->written)],
                ...($report->dryRun ? [] : [
                    ['Unusable match', (string) $report->unusableMatch],
                    ['Failed', (string) $report->failed],
                ]),
            ],
        );

        if ($report->dryRun) {
            $io->success('Dry run finished. Re-run with --apply to geocode missing coordinates, or --apply --force to overwrite existing ones.');
        } elseif ($report->failed > 0 || $report->unusableMatch > 0) {
            $io->warning(sprintf(
                'Wrote %d coordinate pair(s); %d unusable match(es); %d request(s) failed.',
                $report->written,
                $report->unusableMatch,
                $report->failed,
            ));
        } else {
            $io->success(sprintf('Wrote %d coordinate pair(s).', $report->written));
        }

        return Command::SUCCESS;
    }
}
