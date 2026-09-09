<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital;

use App\Allocation\Application\Contracts\HospitalGeocodeClientInterface;
use App\Allocation\Application\Hospital\DTO\HospitalGeocodeMatch;
use App\Allocation\Application\Hospital\DTO\HospitalGeocodeOutcome;
use App\Allocation\Application\Hospital\DTO\HospitalGeocodeReport;
use App\Allocation\Domain\Entity\Hospital;
use Doctrine\ORM\EntityManagerInterface;

final readonly class HospitalGeocodeService
{
    public function __construct(
        private HospitalGeoScopeResolver $scopeResolver,
        private HospitalGeocodeClientInterface $geocodeClient,
        private EntityManagerInterface $entityManager,
        private OpenRouteServiceCallPacer $pacer = new OpenRouteServiceCallPacer(),
    ) {
    }

    public function run(HospitalGeoScope $scope, bool $apply, bool $force, int $delayMs): HospitalGeocodeReport
    {
        $dryRun = !$apply;
        $delayMs = max(0, $delayMs);

        $resolution = $this->scopeResolver->resolve($scope);
        if (!$resolution->success) {
            return new HospitalGeocodeReport(
                success: false,
                dryRun: $dryRun,
                error: $resolution->error ?? 'Hospital geo scope could not be resolved.',
            );
        }

        if ($apply && !$this->geocodeClient->hasApiKey()) {
            return new HospitalGeocodeReport(
                success: false,
                dryRun: false,
                error: 'OPENROUTESERVICE_API_KEY is empty. Set the key before running with --apply.',
            );
        }

        $hospitals = $resolution->hospitals;
        /** @var list<array{0: string, 1: string, 2: string, 3: string, 4: string}> $rows */
        $rows = [];
        /** @var list<array{hospital: Hospital, query: HospitalGeocodeAddress, row: int}> $pending */
        $pending = [];
        $skipped = 0;
        $missingAddress = 0;

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

            $rows[] = [$name, $addressDisplay, $previous, '—', 'geocode'];
            $pending[] = ['hospital' => $hospital, 'query' => $query, 'row' => \count($rows) - 1];
        }

        $toGeocode = \count($pending);
        if ($dryRun) {
            return new HospitalGeocodeReport(
                success: true,
                dryRun: true,
                scopeLabel: $resolution->label,
                rows: $rows,
                inspected: \count($hospitals),
                skipped: $skipped,
                missingAddress: $missingAddress,
                toGeocode: $toGeocode,
            );
        }

        $written = 0;
        $unusableMatch = 0;
        $failed = 0;
        $delayBeforeNextFetch = false;

        foreach ($pending as $item) {
            $hospital = $item['hospital'];
            $query = $item['query'];
            $rowIndex = $item['row'];

            $this->pacer->pauseBetweenCalls($delayBeforeNextFetch, $delayMs);
            $outcome = $this->geocodeWithSingleRetry($query, $delayMs);
            $delayBeforeNextFetch = true;

            if ($outcome->rateLimited) {
                $rows[$rowIndex][4] = 'rate-limited';
                $this->flushIfNeeded($written);

                return new HospitalGeocodeReport(
                    success: true,
                    dryRun: false,
                    scopeLabel: $resolution->label,
                    rows: array_values($rows),
                    inspected: \count($hospitals),
                    skipped: $skipped,
                    missingAddress: $missingAddress,
                    toGeocode: $toGeocode,
                    written: $written,
                    unusableMatch: $unusableMatch,
                    failed: $failed,
                    rateLimited: true,
                );
            }

            if ($outcome->requestFailed) {
                $rows[$rowIndex][4] = 'failed';
                ++$failed;
                continue;
            }

            $match = $outcome->match;
            if ($outcome->unusableMatch || !$match instanceof HospitalGeocodeMatch) {
                $rows[$rowIndex][4] = 'unusable-match';
                ++$unusableMatch;
                continue;
            }

            $this->applyMatch($hospital, $match->latitude, $match->longitude);
            $rows[$rowIndex][3] = $this->formatMatch($match);
            ++$written;
        }

        $this->flushIfNeeded($written);

        return new HospitalGeocodeReport(
            success: true,
            dryRun: false,
            scopeLabel: $resolution->label,
            rows: array_values($rows),
            inspected: \count($hospitals),
            skipped: $skipped,
            missingAddress: $missingAddress,
            toGeocode: $toGeocode,
            written: $written,
            unusableMatch: $unusableMatch,
            failed: $failed,
        );
    }

    private function geocodeWithSingleRetry(HospitalGeocodeAddress $query, int $delayMs): HospitalGeocodeOutcome
    {
        $outcome = $this->geocodeClient->geocodeAddress(
            $query->street,
            $query->postalCode,
            $query->city,
            $query->country,
        );
        if (!$outcome->rateLimited) {
            return $outcome;
        }

        $this->pacer->pauseForRetryAfter($outcome->retryAfterSeconds, $delayMs);

        return $this->geocodeClient->geocodeAddress(
            $query->street,
            $query->postalCode,
            $query->city,
            $query->country,
        );
    }

    private function flushIfNeeded(int $written): void
    {
        if ($written > 0) {
            $this->entityManager->flush();
        }
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
