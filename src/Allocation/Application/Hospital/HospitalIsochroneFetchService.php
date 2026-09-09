<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital;

use App\Allocation\Application\Contracts\HospitalIsochroneClientInterface;
use App\Allocation\Application\Contracts\HospitalIsochroneStoreInterface;
use App\Allocation\Application\Hospital\DTO\HospitalIsochroneFetchOutcome;
use App\Allocation\Application\Hospital\DTO\HospitalIsochroneFetchReport;
use App\Allocation\Domain\Entity\Hospital;

final readonly class HospitalIsochroneFetchService
{
    public function __construct(
        private HospitalGeoScopeResolver $scopeResolver,
        private HospitalIsochroneClientInterface $isochroneClient,
        private HospitalIsochroneStoreInterface $isochroneStore,
        private OpenRouteServiceCallPacer $pacer = new OpenRouteServiceCallPacer(),
    ) {
    }

    public function run(HospitalGeoScope $scope, bool $apply, bool $force, int $delayMs): HospitalIsochroneFetchReport
    {
        $dryRun = !$apply;
        $delayMs = max(0, $delayMs);

        $resolution = $this->scopeResolver->resolve($scope);
        if (!$resolution->success) {
            return new HospitalIsochroneFetchReport(
                success: false,
                dryRun: $dryRun,
                error: $resolution->error ?? 'Hospital geo scope could not be resolved.',
            );
        }

        if ($apply && !$this->isochroneClient->hasApiKey()) {
            return new HospitalIsochroneFetchReport(
                success: false,
                dryRun: false,
                error: 'OPENROUTESERVICE_API_KEY is empty. Set the key before running with --apply.',
            );
        }

        $hospitals = $resolution->hospitals;
        /** @var list<array{0: string, 1: string, 2: string}> $rows */
        $rows = [];
        /** @var list<array{hospital: Hospital, row: int}> $pending */
        $pending = [];
        $missingCoords = 0;
        $skipped = 0;

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

            if (!$force && $this->shouldSkip($hospital, $latitude, $longitude)) {
                $rows[] = [$name, $coordinates, 'skip'];
                ++$skipped;
                continue;
            }

            $rows[] = [$name, $coordinates, 'fetch'];
            $pending[] = ['hospital' => $hospital, 'row' => \count($rows) - 1];
        }

        $toFetch = \count($pending);
        if ($dryRun) {
            return new HospitalIsochroneFetchReport(
                success: true,
                dryRun: true,
                scopeLabel: $resolution->label,
                rows: $rows,
                inspected: \count($hospitals),
                missingCoords: $missingCoords,
                skipped: $skipped,
                toFetch: $toFetch,
            );
        }

        $written = 0;
        $failed = 0;
        $delayBeforeNextFetch = false;

        foreach ($pending as $item) {
            $hospital = $item['hospital'];
            $rowIndex = $item['row'];
            $latitude = $hospital->getLatitude();
            $longitude = $hospital->getLongitude();
            if (null === $latitude || null === $longitude) {
                continue;
            }

            $this->pacer->pauseBetweenCalls($delayBeforeNextFetch, $delayMs);
            $outcome = $this->fetchWithSingleRetry($latitude, $longitude, $delayMs);
            $delayBeforeNextFetch = true;

            if ($outcome->rateLimited) {
                $rows[$rowIndex][2] = 'rate-limited';

                return new HospitalIsochroneFetchReport(
                    success: true,
                    dryRun: false,
                    scopeLabel: $resolution->label,
                    rows: array_values($rows),
                    inspected: \count($hospitals),
                    missingCoords: $missingCoords,
                    skipped: $skipped,
                    toFetch: $toFetch,
                    written: $written,
                    failed: $failed,
                    rateLimited: true,
                );
            }

            if ($outcome->requestFailed || null === $outcome->geojson) {
                $rows[$rowIndex][2] = 'failed';
                ++$failed;
                continue;
            }

            $this->isochroneStore->writeForHospital(
                $hospital,
                IsochroneOrigin::withCoordinates($outcome->geojson, $latitude, $longitude),
            );
            ++$written;
        }

        return new HospitalIsochroneFetchReport(
            success: true,
            dryRun: false,
            scopeLabel: $resolution->label,
            rows: array_values($rows),
            inspected: \count($hospitals),
            missingCoords: $missingCoords,
            skipped: $skipped,
            toFetch: $toFetch,
            written: $written,
            failed: $failed,
        );
    }

    private function shouldSkip(Hospital $hospital, float $latitude, float $longitude): bool
    {
        if (!$this->isochroneStore->existsForHospital($hospital)) {
            return false;
        }

        $existing = $this->isochroneStore->findForHospital($hospital);

        return IsochroneOrigin::matches($existing, $latitude, $longitude);
    }

    private function fetchWithSingleRetry(float $latitude, float $longitude, int $delayMs): HospitalIsochroneFetchOutcome
    {
        $outcome = $this->isochroneClient->fetchDestinationIsochrones($latitude, $longitude);
        if (!$outcome->rateLimited) {
            return $outcome;
        }

        $this->pacer->pauseForRetryAfter($outcome->retryAfterSeconds, $delayMs);

        return $this->isochroneClient->fetchDestinationIsochrones($latitude, $longitude);
    }

    private function formatCoordinates(?float $latitude, ?float $longitude): string
    {
        if (null === $latitude || null === $longitude) {
            return '—';
        }

        return sprintf('%s, %s', $latitude, $longitude);
    }
}
