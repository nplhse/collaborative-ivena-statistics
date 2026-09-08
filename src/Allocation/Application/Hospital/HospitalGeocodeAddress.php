<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital;

use App\Allocation\Domain\Entity\Hospital;

/**
 * Street-level query extracted from a hospital address (with swapped PLZ/city repaired).
 */
final readonly class HospitalGeocodeAddress
{
    public function __construct(
        public string $street,
        public string $postalCode,
        public string $city,
        public string $country,
    ) {
    }

    public static function fromHospital(Hospital $hospital): ?self
    {
        $address = $hospital->getAddress();
        $street = trim($address->getStreet());
        $postalCode = trim($address->getPostalCode());
        $city = trim($address->getCity());
        $country = self::countryCode(trim($address->getCountry()));

        if (self::looksLikePostalCode($city) && !self::looksLikePostalCode($postalCode)) {
            [$postalCode, $city] = [$city, $postalCode];
        }

        if ('' === $street || ('' === $postalCode && '' === $city)) {
            return null;
        }

        return new self($street, $postalCode, $city, $country);
    }

    public function display(): string
    {
        $parts = array_values(array_filter(
            [$this->street, trim($this->postalCode.' '.$this->city)],
            static fn (string $part): bool => '' !== $part,
        ));

        return implode(', ', $parts);
    }

    private static function looksLikePostalCode(string $value): bool
    {
        return 1 === preg_match('/^\d{5}$/', $value);
    }

    private static function countryCode(string $country): string
    {
        return match (mb_strtolower($country)) {
            '', 'de', 'deu', 'deutschland', 'germany' => 'DE',
            default => strtoupper($country),
        };
    }
}
