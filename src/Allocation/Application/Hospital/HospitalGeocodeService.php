<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital;

use App\Allocation\Application\Contracts\HospitalGeocodeClientInterface;
use App\Allocation\Application\Contracts\HospitalLookupInterface;
use App\Allocation\Application\Contracts\StateLookupInterface;
use App\Allocation\Application\Hospital\DTO\HospitalGeocodeMatch;
use App\Allocation\Application\Hospital\DTO\HospitalGeocodeReport;
use App\Allocation\Domain\Entity\Hospital;
use Doctrine\ORM\EntityManagerInterface;

final readonly class HospitalGeocodeService
{
    public function __construct(
        private StateLookupInterface $stateLookup,
        private HospitalLookupInterface $hospitalLookup,
        private HospitalGeocodeClientInterface $geocodeClient,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function run(int $stateId, bool $apply, bool $force, int $delayMs): HospitalGeocodeReport
    {
        $dryRun = !$apply;
        $delayMs = max(0, $delayMs);

        $state = $this->stateLookup->findById($stateId);
        if (!$state instanceof \App\Allocation\Domain\Entity\State) {
            return new HospitalGeocodeReport(
                success: false,
                dryRun: $dryRun,
                error: sprintf('Unknown federal state #%d.', $stateId),
            );
        }

        if ($apply && !$this->geocodeClient->hasApiKey()) {
            return new HospitalGeocodeReport(
                success: false,
                dryRun: false,
                error: 'OPENROUTESERVICE_API_KEY is empty. Set the key before running with --apply.',
            );
        }

        $hospitals = $this->hospitalLookup->findByState($state);
        $rows = [];
        $skipped = 0;
        $missingAddress = 0;
        $toGeocode = 0;
        $written = 0;
        $unusableMatch = 0;
        $failed = 0;
        $delayBeforeNextFetch = false;

        foreach ($hospitals as $hospital) {
            $name = $hospital->getName() ?? '';
            $previous = $this->formatCoordinates($hospital->getLatitude(), $hospital->getLongitude());
            $query = HospitalGeocodeAddress::fromHospital($hospital);
            $addressDisplay = $query?->display() ?? '—';

            if (!$query instanceof HospitalGeocodeAddress) {
                $rows[] = [$name, $addressDisplay, $previous, '—', 'missing-address'];
                ++$missingAddress;
                continue;
            }

            if (!$force && null !== $hospital->getLatitude() && null !== $hospital->getLongitude()) {
                $rows[] = [$name, $addressDisplay, $previous, $previous, 'skip'];
                ++$skipped;
                continue;
            }

            ++$toGeocode;
            if ($dryRun) {
                $rows[] = [$name, $addressDisplay, $previous, '—', 'geocode'];
                continue;
            }

            if ($delayBeforeNextFetch && $delayMs > 0) {
                usleep($delayMs * 1000);
            }

            $outcome = $this->geocodeClient->geocodeAddress(
                $query->street,
                $query->postalCode,
                $query->city,
                $query->country,
            );
            $delayBeforeNextFetch = true;

            if ($outcome->requestFailed) {
                $rows[] = [$name, $addressDisplay, $previous, '—', 'failed'];
                ++$failed;
                continue;
            }

            $match = $outcome->match;
            if ($outcome->unusableMatch || !$match instanceof HospitalGeocodeMatch) {
                $rows[] = [$name, $addressDisplay, $previous, '—', 'unusable-match'];
                ++$unusableMatch;
                continue;
            }

            $this->applyMatch($hospital, $match->latitude, $match->longitude);
            $rows[] = [$name, $addressDisplay, $previous, $this->formatMatch($match), 'geocode'];
            ++$written;
        }

        if ($apply && $written > 0) {
            $this->entityManager->flush();
        }

        return new HospitalGeocodeReport(
            success: true,
            dryRun: $dryRun,
            rows: $rows,
            inspected: \count($hospitals),
            skipped: $skipped,
            missingAddress: $missingAddress,
            toGeocode: $toGeocode,
            written: $written,
            unusableMatch: $unusableMatch,
            failed: $failed,
        );
    }

    private function applyMatch(Hospital $hospital, float $latitude, float $longitude): void
    {
        $hospital->setLatitude($latitude);
        $hospital->setLongitude($longitude);
    }

    private function formatMatch(HospitalGeocodeMatch $match): string
    {
        return sprintf('%s [%s] %s', $this->formatCoordinates($match->latitude, $match->longitude), $match->layer, $match->label);
    }

    private function formatCoordinates(?float $latitude, ?float $longitude): string
    {
        if (null === $latitude || null === $longitude) {
            return '—';
        }

        return sprintf('%s, %s', $latitude, $longitude);
    }
}
