<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital;

use App\Allocation\Application\Contracts\HospitalIsochroneClientInterface;
use App\Allocation\Application\Contracts\HospitalIsochroneStoreInterface;
use App\Allocation\Application\Contracts\HospitalLookupInterface;
use App\Allocation\Application\Contracts\StateLookupInterface;
use App\Allocation\Application\Hospital\DTO\HospitalIsochroneFetchReport;

final readonly class HospitalIsochroneFetchService
{
    public function __construct(
        private StateLookupInterface $stateLookup,
        private HospitalLookupInterface $hospitalLookup,
        private HospitalIsochroneClientInterface $isochroneClient,
        private HospitalIsochroneStoreInterface $isochroneStore,
    ) {
    }

    public function run(int $stateId, bool $apply, bool $force, int $delayMs): HospitalIsochroneFetchReport
    {
        $dryRun = !$apply;
        $delayMs = max(0, $delayMs);

        $state = $this->stateLookup->findById($stateId);
        if (!$state instanceof \App\Allocation\Domain\Entity\State) {
            return new HospitalIsochroneFetchReport(
                success: false,
                dryRun: $dryRun,
                error: sprintf('Unknown federal state #%d.', $stateId),
            );
        }

        if ($apply && !$this->isochroneClient->hasApiKey()) {
            return new HospitalIsochroneFetchReport(
                success: false,
                dryRun: false,
                error: 'OPENROUTESERVICE_API_KEY is empty. Set the key before running with --apply.',
            );
        }

        $hospitals = $this->hospitalLookup->findByState($state);
        $rows = [];
        $missingCoords = 0;
        $skipped = 0;
        $toFetch = 0;
        $written = 0;
        $failed = 0;
        $delayBeforeNextFetch = false;

        foreach ($hospitals as $hospital) {
            $name = $hospital->getName() ?? '';
            $latitude = $hospital->getLatitude();
            $longitude = $hospital->getLongitude();
            $coordinates = $this->formatCoordinates($latitude, $longitude);

            if (null === $latitude || null === $longitude) {
                $rows[] = [$name, $coordinates, 'missing-coords'];
                ++$missingCoords;
                continue;
            }

            if (!$force && $this->isochroneStore->existsForHospital($hospital)) {
                $existing = $this->isochroneStore->findForHospital($hospital);
                if (IsochroneOrigin::matches($existing, $latitude, $longitude)) {
                    $rows[] = [$name, $coordinates, 'skip'];
                    ++$skipped;
                    continue;
                }
            }

            ++$toFetch;
            if ($dryRun) {
                $rows[] = [$name, $coordinates, 'fetch'];
                continue;
            }

            if ($delayBeforeNextFetch && $delayMs > 0) {
                usleep($delayMs * 1000);
            }

            $geojson = $this->isochroneClient->fetchDestinationIsochrones($latitude, $longitude);
            $delayBeforeNextFetch = true;
            if (null === $geojson) {
                $rows[] = [$name, $coordinates, 'fetch'];
                ++$failed;
                continue;
            }

            $this->isochroneStore->writeForHospital(
                $hospital,
                IsochroneOrigin::withCoordinates($geojson, $latitude, $longitude),
            );
            $rows[] = [$name, $coordinates, 'fetch'];
            ++$written;
        }

        return new HospitalIsochroneFetchReport(
            success: true,
            dryRun: $dryRun,
            rows: $rows,
            inspected: \count($hospitals),
            missingCoords: $missingCoords,
            skipped: $skipped,
            toFetch: $toFetch,
            written: $written,
            failed: $failed,
        );
    }

    private function formatCoordinates(?float $latitude, ?float $longitude): string
    {
        if (null === $latitude || null === $longitude) {
            return '—';
        }

        return sprintf('%s, %s', $latitude, $longitude);
    }
}
