<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\PreparedLocation;

use App\Allocation\Application\Hospital\HospitalGeocodeAddress;

/**
 * What to prepare: exactly one source of coordinates (an existing hospital, a manual pin, or an
 * address to geocode).
 */
final readonly class PrepareLocationRequest
{
    public function __construct(
        public string $slug,
        public ?string $label = null,
        public ?HospitalGeocodeAddress $address = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?int $hospitalId = null,
    ) {
    }

    public function sourceError(): ?string
    {
        if (!PreparedLocation::isValidSlug($this->slug)) {
            return sprintf('Invalid slug "%s". Use lowercase letters, digits and hyphens (for example "klinikum-musterstadt").', $this->slug);
        }

        $hasCoordinates = null !== $this->latitude || null !== $this->longitude;
        if ($hasCoordinates && (null === $this->latitude || null === $this->longitude)) {
            return 'Pass both --lat and --lng.';
        }

        if (null !== $this->latitude && ($this->latitude < -90.0 || $this->latitude > 90.0)) {
            return 'Latitude must be between -90 and 90.';
        }

        if (null !== $this->longitude && ($this->longitude < -180.0 || $this->longitude > 180.0)) {
            return 'Longitude must be between -180 and 180.';
        }

        if (null !== $this->hospitalId) {
            return $hasCoordinates || $this->address instanceof HospitalGeocodeAddress
                ? 'Use either --from-hospital-id or an address/coordinates, not both.'
                : null;
        }

        if ($hasCoordinates) {
            return null;
        }

        $address = $this->address;
        if (!$address instanceof HospitalGeocodeAddress || '' === $address->street || ('' === $address->postalCode && '' === $address->city)) {
            return 'Specify --from-hospital-id, --lat/--lng, or an address (--street and --postal-code or --city).';
        }

        return null;
    }
}
