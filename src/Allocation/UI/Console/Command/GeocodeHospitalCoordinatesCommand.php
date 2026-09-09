<?php

declare(strict_types=1);

namespace App\Allocation\UI\Console\Command;

use App\Allocation\Application\Hospital\HospitalGeocodeService;
use App\Allocation\UI\Console\Input\HospitalGeoCommandInput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:geo:geocode-hospitals',
    description: 'Geocode hospital street addresses to coordinates (hospital, dispatch area, or state; default: dry-run preview).',
)]
final readonly class GeocodeHospitalCoordinatesCommand
{
    public function __construct(
        private HospitalGeocodeService $geocodeService,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[MapInput] HospitalGeoCommandInput $input,
    ): int {
        $apply = $input->apply;
        if ($apply && $input->dryRun) {
            $io->warning('Both --apply and --dry-run were passed; --apply takes precedence.');
        }

        $scopeError = $input->scopeError();
        if (null !== $scopeError) {
            $io->error($scopeError);

            return Command::FAILURE;
        }

        $io->title('Geocode hospital coordinates');
        if ($input->ignoresParticipatingOnly()) {
            $io->warning('--participating-only is ignored when --hospital-id is set.');
        }

        $report = $this->geocodeService->run($input->toScope(), $apply, $input->force, $input->delayMs);
        if (!$report->success) {
            $io->error($report->error ?? 'Hospital geocoding failed.');

            return Command::FAILURE;
        }

        if ('' !== $report->scopeLabel) {
            $io->text(sprintf('Scope: %s', $report->scopeLabel));
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
                ['OpenRouteService requests required', (string) $report->toGeocode],
                ...($report->dryRun ? [] : [
                    ['Geocoded', (string) $report->written],
                    ['Unusable match', (string) $report->unusableMatch],
                    ['Failed', (string) $report->failed],
                ]),
            ],
        );

        if ($report->rateLimited) {
            $io->error(sprintf(
                'OpenRouteService rate limit reached after writing %d coordinate pair(s). Re-run later to resume; already stored coordinates are kept.',
                $report->written,
            ));

            return Command::FAILURE;
        }

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
