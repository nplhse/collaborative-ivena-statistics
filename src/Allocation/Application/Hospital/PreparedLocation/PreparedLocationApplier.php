<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\PreparedLocation;

use App\Allocation\Application\Contracts\HospitalIsochroneStoreInterface;
use App\Allocation\Application\Contracts\PreparedLocationStoreInterface;
use App\Allocation\Application\Hospital\HospitalGeocodeAddress;
use App\Allocation\Application\Hospital\IsochroneOrigin;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\State;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Applies a prepared location to a hospital without network access: sets its coordinates, fills an
 * empty address and writes the isochrone file through the regular store, so every map reads it as
 * usual. Different existing coordinates are only replaced with $force.
 */
final readonly class PreparedLocationApplier
{
    public function __construct(
        private PreparedLocationStoreInterface $store,
        private HospitalIsochroneStoreInterface $isochroneStore,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function run(string $slug, Hospital $hospital, bool $apply, bool $force): ApplyPreparedLocationReport
    {
        $dryRun = !$apply;

        $location = $this->store->find($slug);
        if (!$location instanceof PreparedLocation) {
            return new ApplyPreparedLocationReport(
                success: false,
                dryRun: $dryRun,
                error: sprintf('Unknown or unreadable prepared location "%s". List them with app:geo:list-prepared-locations.', $slug),
            );
        }

        $state = $hospital->getState();
        if (!$state instanceof State || null === $state->getId() || !$hospital->getPublicId() instanceof Uuid) {
            return new ApplyPreparedLocationReport(success: false, dryRun: $dryRun, error: 'The hospital needs a federal state and a public id before isochrones can be stored.');
        }

        $currentLatitude = $hospital->getLatitude();
        $currentLongitude = $hospital->getLongitude();
        $hasCoordinates = null !== $currentLatitude && null !== $currentLongitude;
        $sameCoordinates = $hasCoordinates && IsochroneOrigin::matches($location->isochrones, $currentLatitude, $currentLongitude);
        $coordinatesStatus = match (true) {
            !$hasCoordinates => 'set',
            $sameCoordinates => 'unchanged',
            default => 'replace',
        };

        $fillAddress = $location->address instanceof HospitalGeocodeAddress && $this->hasEmptyAddress($hospital);
        $writeIsochrones = !$sameCoordinates || !IsochroneOrigin::matches(
            $this->isochroneStore->findForHospital($hospital),
            $location->latitude,
            $location->longitude,
        );

        $rows = [
            ['Coordinates', $this->formatCoordinates($currentLatitude, $currentLongitude), $this->formatCoordinates($location->latitude, $location->longitude), $coordinatesStatus],
            ['Address', $this->formatAddress($hospital), $location->address?->display() ?? '—', $fillAddress ? 'fill' : 'keep'],
            ['Isochrones', $this->isochroneStore->existsForHospital($hospital) ? 'file' : '—', sprintf('%d band(s)', \count($location->isochrones['features'])), $writeIsochrones ? 'write' : 'unchanged'],
        ];
        $changed = 'unchanged' !== $coordinatesStatus || $fillAddress || $writeIsochrones;

        if ('replace' === $coordinatesStatus && !$force) {
            return new ApplyPreparedLocationReport(
                success: false,
                dryRun: $dryRun,
                error: 'The hospital already has different coordinates. Pass --force to replace them.',
                rows: $rows,
            );
        }

        if ($dryRun || !$changed) {
            return new ApplyPreparedLocationReport(success: true, dryRun: $dryRun, rows: $rows, changed: $changed);
        }

        $hospital->setLatitude($location->latitude);
        $hospital->setLongitude($location->longitude);
        if ($fillAddress && $location->address instanceof HospitalGeocodeAddress) {
            $hospital->getAddress()
                ->setStreet($location->address->street)
                ->setPostalCode($location->address->postalCode)
                ->setCity($location->address->city)
                ->setCountry($location->address->country);
        }

        // Coordinates first: a failed file write leaves an origin mismatch that app:geo:fetch-isochrones detects.
        $this->entityManager->flush();
        if ($writeIsochrones) {
            $this->isochroneStore->writeForHospital($hospital, $location->isochrones);
        }

        return new ApplyPreparedLocationReport(success: true, dryRun: false, rows: $rows, changed: true);
    }

    private function hasEmptyAddress(Hospital $hospital): bool
    {
        $address = $hospital->getAddress();

        return '' === trim($address->getStreet())
            && '' === trim($address->getPostalCode())
            && '' === trim($address->getCity());
    }

    private function formatAddress(Hospital $hospital): string
    {
        $address = HospitalGeocodeAddress::fromHospital($hospital);

        return $address?->display() ?? '—';
    }

    private function formatCoordinates(?float $latitude, ?float $longitude): string
    {
        if (null === $latitude || null === $longitude) {
            return '—';
        }

        return sprintf('%s, %s', $latitude, $longitude);
    }
}
