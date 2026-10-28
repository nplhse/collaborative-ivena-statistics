<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\PreparedLocation;

use App\Allocation\Application\Contracts\HospitalGeocodeClientInterface;
use App\Allocation\Application\Contracts\HospitalIsochroneClientInterface;
use App\Allocation\Application\Contracts\HospitalIsochroneProviderInterface;
use App\Allocation\Application\Contracts\HospitalLookupInterface;
use App\Allocation\Application\Contracts\PreparedLocationStoreInterface;
use App\Allocation\Application\Hospital\DTO\HospitalGeocodeMatch;
use App\Allocation\Application\Hospital\DTO\HospitalGeocodeOutcome;
use App\Allocation\Application\Hospital\DTO\HospitalIsochroneFetchOutcome;
use App\Allocation\Application\Hospital\HospitalGeocodeAddress;
use App\Allocation\Application\Hospital\IsochroneOrigin;
use App\Allocation\Application\Hospital\OpenRouteServiceCallPacer;
use App\Allocation\Domain\Entity\Hospital;
use Psr\Clock\ClockInterface;

/**
 * Prepares a location while online: coordinates (from a hospital, a manual pin, or geocoding) and
 * destination isochrones (reused from the hospital's file or fetched), stored by slug.
 * Uses the same OpenRouteService clients as the hospital geo commands.
 */
final readonly class PreparedLocationPreparer
{
    public function __construct(
        private HospitalLookupInterface $hospitalLookup,
        private HospitalGeocodeClientInterface $geocodeClient,
        private HospitalIsochroneClientInterface $isochroneClient,
        private HospitalIsochroneProviderInterface $isochroneProvider,
        private PreparedLocationStoreInterface $store,
        private ClockInterface $clock,
        private OpenRouteServiceCallPacer $pacer = new OpenRouteServiceCallPacer(),
    ) {
    }

    public function run(PrepareLocationRequest $request, bool $apply, bool $force, int $delayMs): PrepareLocationReport
    {
        $dryRun = !$apply;
        $delayMs = max(0, $delayMs);

        $error = $request->sourceError();
        if (null !== $error) {
            return $this->failure($dryRun, $error);
        }

        if (!$force && $this->store->exists($request->slug)) {
            return $this->failure($dryRun, sprintf('Prepared location "%s" already exists. Pass --force to replace it.', $request->slug));
        }

        $hospital = null;
        if (null !== $request->hospitalId) {
            $hospital = $this->hospitalLookup->findById($request->hospitalId);
            if (!$hospital instanceof Hospital) {
                return $this->failure($dryRun, sprintf('Unknown hospital id %d.', $request->hospitalId));
            }

            if (null === $hospital->getLatitude() || null === $hospital->getLongitude()) {
                return $this->failure($dryRun, 'The hospital has no coordinates. Run app:geo:geocode-hospitals first, or prepare from an address or --lat/--lng.');
            }
        }

        $address = $hospital instanceof Hospital ? HospitalGeocodeAddress::fromHospital($hospital) : $request->address;
        $label = $this->label($request, $hospital, $address);
        $needsGeocode = !$hospital instanceof Hospital && null === $request->latitude;
        $reusableIsochrones = $hospital instanceof Hospital ? $this->reusableIsochrones($hospital) : null;
        $needsIsochrones = null === $reusableIsochrones;

        $steps = [
            ['Location', sprintf('%s (%s)', $label, $request->slug), $this->store->exists($request->slug) ? 'replace' : 'new'],
            $this->coordinatesStep($request, $hospital, $address, $needsGeocode),
            ['Isochrones', $needsIsochrones ? 'OpenRouteService, driving-car, 5–50 min' : 'existing file of the hospital', $needsIsochrones ? 'fetch' : 'reuse'],
        ];
        $requestsRequired = ($needsGeocode ? 1 : 0) + ($needsIsochrones ? 1 : 0);

        if ($dryRun) {
            return new PrepareLocationReport(success: true, dryRun: true, steps: $steps, requestsRequired: $requestsRequired);
        }

        if ($requestsRequired > 0 && (!$this->geocodeClient->hasApiKey() || !$this->isochroneClient->hasApiKey())) {
            return $this->failure(false, 'OPENROUTESERVICE_API_KEY is empty. Set the key before running with --apply.');
        }

        $executed = 0;
        [$latitude, $longitude] = $this->knownCoordinates($request, $hospital);
        if ($needsGeocode && $address instanceof HospitalGeocodeAddress) {
            $outcome = $this->geocodeWithSingleRetry($address, $delayMs);
            ++$executed;
            $match = $outcome->match;
            if ($outcome->rateLimited || $outcome->requestFailed || !$match instanceof HospitalGeocodeMatch) {
                $steps[1][2] = $this->failedStatus($outcome->rateLimited, $outcome->requestFailed, 'unusable-match');

                return new PrepareLocationReport(
                    success: false,
                    dryRun: false,
                    error: $outcome->rateLimited
                        ? 'OpenRouteService rate limit reached. Re-run later.'
                        : ($outcome->requestFailed ? 'Geocoding request failed.' : 'No street-level match for this address. Check it or pass --lat/--lng.'),
                    steps: $steps,
                    requestsRequired: $requestsRequired,
                    requestsExecuted: $executed,
                    rateLimited: $outcome->rateLimited,
                );
            }

            $latitude = $match->latitude;
            $longitude = $match->longitude;
            $steps[1][1] = sprintf('%s, %s [%s] %s', $latitude, $longitude, $match->layer, $match->label);
        }

        if (null === $latitude || null === $longitude) {
            return $this->failure(false, 'No coordinates could be determined.');
        }

        $isochrones = $reusableIsochrones;
        if (null === $isochrones) {
            $this->pacer->pauseBetweenCalls($executed > 0, $delayMs);
            $outcome = $this->fetchWithSingleRetry($latitude, $longitude, $delayMs);
            ++$executed;
            if ($outcome->rateLimited || $outcome->requestFailed || null === $outcome->geojson) {
                $steps[2][2] = $this->failedStatus($outcome->rateLimited, true, 'failed');

                return new PrepareLocationReport(
                    success: false,
                    dryRun: false,
                    error: $outcome->rateLimited ? 'OpenRouteService rate limit reached. Re-run later.' : 'Isochrone request failed.',
                    steps: $steps,
                    requestsRequired: $requestsRequired,
                    requestsExecuted: $executed,
                    rateLimited: $outcome->rateLimited,
                );
            }

            $isochrones = IsochroneOrigin::withCoordinates($outcome->geojson, $latitude, $longitude);
        }

        $location = new PreparedLocation(
            slug: $request->slug,
            label: $label,
            address: $address,
            latitude: $latitude,
            longitude: $longitude,
            isochrones: $isochrones,
            preparedAt: $this->clock->now(),
        );
        $this->store->save($location);

        return new PrepareLocationReport(
            success: true,
            dryRun: false,
            steps: $steps,
            requestsRequired: $requestsRequired,
            requestsExecuted: $executed,
            location: $location,
        );
    }

    /**
     * @return array{type: string, features: list<array<string, mixed>>, properties?: array<string, mixed>}|null
     */
    private function reusableIsochrones(Hospital $hospital): ?array
    {
        $latitude = $hospital->getLatitude();
        $longitude = $hospital->getLongitude();
        if (null === $latitude || null === $longitude) {
            return null;
        }

        $existing = $this->isochroneProvider->findForHospital($hospital);

        return IsochroneOrigin::matches($existing, $latitude, $longitude) ? $existing : null;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function coordinatesStep(PrepareLocationRequest $request, ?Hospital $hospital, ?HospitalGeocodeAddress $address, bool $needsGeocode): array
    {
        if ($needsGeocode) {
            return ['Coordinates', $address?->display() ?? '—', 'geocode'];
        }

        [$latitude, $longitude] = $this->knownCoordinates($request, $hospital);

        return ['Coordinates', sprintf('%s, %s', $latitude ?? '—', $longitude ?? '—'), $hospital instanceof Hospital ? 'from hospital' : 'manual'];
    }

    /**
     * @return array{0: float|null, 1: float|null}
     */
    private function knownCoordinates(PrepareLocationRequest $request, ?Hospital $hospital): array
    {
        if ($hospital instanceof Hospital) {
            return [$hospital->getLatitude(), $hospital->getLongitude()];
        }

        return [$request->latitude, $request->longitude];
    }

    private function label(PrepareLocationRequest $request, ?Hospital $hospital, ?HospitalGeocodeAddress $address): string
    {
        $label = trim($request->label ?? '');
        if ('' !== $label) {
            return $label;
        }

        $name = trim($hospital?->getName() ?? '');
        if ('' !== $name) {
            return $name;
        }

        return $address?->display() ?? $request->slug;
    }

    private function failedStatus(bool $rateLimited, bool $requestFailed, string $otherwise): string
    {
        if ($rateLimited) {
            return 'rate-limited';
        }

        return $requestFailed ? 'failed' : $otherwise;
    }

    private function failure(bool $dryRun, string $error): PrepareLocationReport
    {
        return new PrepareLocationReport(success: false, dryRun: $dryRun, error: $error);
    }

    private function geocodeWithSingleRetry(HospitalGeocodeAddress $address, int $delayMs): HospitalGeocodeOutcome
    {
        $outcome = $this->geocodeClient->geocodeAddress($address->street, $address->postalCode, $address->city, $address->country);
        if (!$outcome->rateLimited) {
            return $outcome;
        }

        $this->pacer->pauseForRetryAfter($outcome->retryAfterSeconds, $delayMs);

        return $this->geocodeClient->geocodeAddress($address->street, $address->postalCode, $address->city, $address->country);
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
}
