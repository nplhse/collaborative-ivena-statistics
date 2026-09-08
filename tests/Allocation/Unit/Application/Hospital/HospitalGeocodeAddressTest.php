<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Hospital;

use App\Allocation\Application\Hospital\HospitalGeocodeAddress;
use App\Allocation\Domain\Entity\Hospital;
use PHPUnit\Framework\TestCase;

final class HospitalGeocodeAddressTest extends TestCase
{
    public function testFromHospitalUsesStreetPostalCodeAndCity(): void
    {
        $query = HospitalGeocodeAddress::fromHospital($this->hospital(
            street: 'Mönchebergstraße 41-43',
            postalCode: '34125',
            city: 'Kassel',
        ));

        self::assertNotNull($query);
        self::assertSame('Mönchebergstraße 41-43', $query->street);
        self::assertSame('34125', $query->postalCode);
        self::assertSame('Kassel', $query->city);
        self::assertSame('DE', $query->country);
        self::assertSame('Mönchebergstraße 41-43, 34125 Kassel', $query->display());
    }

    public function testFromHospitalSwapsNumericCityWithNonNumericPostalCode(): void
    {
        $query = HospitalGeocodeAddress::fromHospital($this->hospital(
            street: 'Hansteinstraße 29',
            postalCode: 'Kassel',
            city: '34121',
        ));

        self::assertNotNull($query);
        self::assertSame('34121', $query->postalCode);
        self::assertSame('Kassel', $query->city);
    }

    public function testFromHospitalMapsEmptyAndGermanCountryNamesToDe(): void
    {
        $empty = HospitalGeocodeAddress::fromHospital($this->hospital(
            street: 'Mönchebergstraße 41-43',
            postalCode: '34125',
            city: 'Kassel',
            country: '',
        ));
        $english = HospitalGeocodeAddress::fromHospital($this->hospital(
            street: 'Mönchebergstraße 41-43',
            postalCode: '34125',
            city: 'Kassel',
            country: 'Germany',
        ));
        $iso = HospitalGeocodeAddress::fromHospital($this->hospital(
            street: 'Mönchebergstraße 41-43',
            postalCode: '34125',
            city: 'Kassel',
            country: 'deu',
        ));

        self::assertSame('DE', $empty?->country);
        self::assertSame('DE', $english?->country);
        self::assertSame('DE', $iso?->country);
    }

    public function testFromHospitalKeepsUnknownCountryCodesUppercased(): void
    {
        $query = HospitalGeocodeAddress::fromHospital($this->hospital(
            street: 'Main Street 1',
            postalCode: '10001',
            city: 'New York',
            country: 'us',
        ));

        self::assertNotNull($query);
        self::assertSame('US', $query->country);
    }

    public function testDisplayOmitsEmptyPostalOrCityParts(): void
    {
        $streetAndCity = HospitalGeocodeAddress::fromHospital($this->hospital(
            street: 'Mönchebergstraße 41-43',
            postalCode: '',
            city: 'Kassel',
        ));
        $streetAndPostal = HospitalGeocodeAddress::fromHospital($this->hospital(
            street: 'Mönchebergstraße 41-43',
            postalCode: '34125',
            city: '',
        ));

        self::assertSame('Mönchebergstraße 41-43, Kassel', $streetAndCity?->display());
        self::assertSame('Mönchebergstraße 41-43, 34125', $streetAndPostal?->display());
    }

    public function testFromHospitalReturnsNullWithoutStreet(): void
    {
        self::assertNull(HospitalGeocodeAddress::fromHospital($this->hospital(
            street: '',
            postalCode: '34125',
            city: 'Kassel',
        )));
    }

    private function hospital(string $street, string $postalCode, string $city, string $country = 'Deutschland'): Hospital
    {
        $hospital = new Hospital()->setName('Klinik');
        $hospital->getAddress()
            ->setStreet($street)
            ->setPostalCode($postalCode)
            ->setCity($city)
            ->setCountry($country);

        return $hospital;
    }
}
