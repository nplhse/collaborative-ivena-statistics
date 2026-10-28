<?php

declare(strict_types=1);

namespace App\Allocation\UI\Console\Input;

use App\Allocation\Application\Hospital\HospitalGeocodeAddress;
use App\Allocation\Application\Hospital\PreparedLocation\PrepareLocationRequest;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Validator\Constraints as Assert;

final class PrepareLocationCommandInput
{
    #[Argument(description: 'Short name of the prepared location (lowercase letters, digits, hyphens)', name: 'slug')]
    public string $slug = '';

    #[Option(description: 'Display name (default: hospital name or address)', name: 'label')]
    public ?string $label = null;

    #[Option(description: 'Street and house number to geocode', name: 'street')]
    public ?string $street = null;

    #[Option(description: 'Postal code', name: 'postal-code')]
    public ?string $postalCode = null;

    #[Option(description: 'City', name: 'city')]
    public ?string $city = null;

    #[Option(description: 'Country (default: DE)', name: 'country')]
    public string $country = 'DE';

    #[Option(description: 'Latitude of a manual pin (skips geocoding)', name: 'lat')]
    public ?string $latitude = null;

    #[Option(description: 'Longitude of a manual pin (skips geocoding)', name: 'lng')]
    public ?string $longitude = null;

    #[Option(description: 'Copy coordinates and isochrones of an existing hospital (Doctrine ID)', name: 'from-hospital-id')]
    #[Assert\Positive]
    public ?int $hospitalId = null;

    #[Option(description: 'Preview without calling OpenRouteService (default)', name: 'dry-run')]
    public bool $dryRun = false;

    #[Option(description: 'Call OpenRouteService if needed and store the location', name: 'apply')]
    public bool $apply = false;

    #[Option(description: 'Replace an existing prepared location with the same slug', name: 'force')]
    public bool $force = false;

    #[Option(description: 'Pause between OpenRouteService requests in milliseconds (default: 3500)', name: 'delay-ms')]
    public int $delayMs = 3500;

    public function coordinateError(): ?string
    {
        foreach (['--lat' => $this->latitude, '--lng' => $this->longitude] as $option => $value) {
            if (null !== $value && !is_numeric(trim($value))) {
                return sprintf('%s must be a decimal number.', $option);
            }
        }

        return null;
    }

    public function toRequest(): PrepareLocationRequest
    {
        return new PrepareLocationRequest(
            slug: trim($this->slug),
            label: $this->label,
            address: $this->address(),
            latitude: null !== $this->latitude ? (float) trim($this->latitude) : null,
            longitude: null !== $this->longitude ? (float) trim($this->longitude) : null,
            hospitalId: $this->hospitalId,
        );
    }

    private function address(): ?HospitalGeocodeAddress
    {
        $street = trim($this->street ?? '');
        $postalCode = trim($this->postalCode ?? '');
        $city = trim($this->city ?? '');
        if ('' === $street && '' === $postalCode && '' === $city) {
            return null;
        }

        return new HospitalGeocodeAddress($street, $postalCode, $city, strtoupper(trim($this->country)));
    }
}
