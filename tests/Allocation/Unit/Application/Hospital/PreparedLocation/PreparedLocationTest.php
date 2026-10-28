<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Hospital\PreparedLocation;

use App\Allocation\Application\Hospital\HospitalGeocodeAddress;
use App\Allocation\Application\Hospital\IsochroneOrigin;
use App\Allocation\Application\Hospital\PreparedLocation\PreparedLocation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PreparedLocationTest extends TestCase
{
    #[DataProvider('slugs')]
    public function testSlugValidation(string $slug, bool $valid): void
    {
        self::assertSame($valid, PreparedLocation::isValidSlug($slug));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function slugs(): iterable
    {
        yield 'simple' => ['musterstadt', true];
        yield 'with hyphen and digits' => ['klinikum-musterstadt-2', true];
        yield 'single character' => ['a', true];
        yield 'empty' => ['', false];
        yield 'uppercase' => ['Musterstadt', false];
        yield 'leading hyphen' => ['-musterstadt', false];
        yield 'trailing hyphen' => ['musterstadt-', false];
        yield 'path traversal' => ['../musterstadt', false];
        yield 'umlaut' => ['münster', false];
        yield 'too long' => [str_repeat('a', 65), false];
    }

    public function testArrayRoundTripKeepsAllFields(): void
    {
        $location = $this->location();

        $copy = PreparedLocation::fromArray($location->toArray());

        self::assertEquals($location, $copy);
        self::assertSame(1, $location->toArray()['formatVersion']);
        self::assertSame(['lat' => 50.5, 'lng' => 8.25], $location->toArray()['coordinates']);
    }

    public function testRejectsIsochronesComputedForOtherCoordinates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('were not computed for its coordinates');

        new PreparedLocation(
            slug: 'musterstadt',
            label: 'Musterstadt',
            address: null,
            latitude: 50.5,
            longitude: 8.25,
            isochrones: IsochroneOrigin::withCoordinates($this->geojson(), 50.6, 8.25),
            preparedAt: new \DateTimeImmutable('2026-10-28T12:00:00+00:00'),
        );
    }

    public function testRejectsUnknownFormatVersion(): void
    {
        $data = $this->location()->toArray();
        $data['formatVersion'] = 2;

        $this->expectException(\InvalidArgumentException::class);
        PreparedLocation::fromArray($data);
    }

    public function testConstructorRejectsInvalidSlug(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid prepared location slug "Muster Stadt"');

        new PreparedLocation(
            slug: 'Muster Stadt',
            label: 'Musterstadt',
            address: null,
            latitude: 50.5,
            longitude: 8.25,
            isochrones: IsochroneOrigin::withCoordinates($this->geojson(), 50.5, 8.25),
            preparedAt: new \DateTimeImmutable('2026-10-28T12:00:00+00:00'),
        );
    }

    /**
     * @param \Closure(array<string, mixed>): array<string, mixed> $break
     */
    #[DataProvider('malformedFiles')]
    public function testRejectsMalformedData(\Closure $break, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        PreparedLocation::fromArray($break($this->location()->toArray()));
    }

    /**
     * @return iterable<string, array{\Closure(array<string, mixed>): array<string, mixed>, string}>
     */
    public static function malformedFiles(): iterable
    {
        yield 'coordinates missing' => [static fn (array $d): array => array_diff_key($d, ['coordinates' => true]), 'missing coordinates or isochrones'];
        yield 'isochrones not an object' => [static fn (array $d): array => ['isochrones' => 'none'] + $d, 'missing coordinates or isochrones'];
        yield 'timestamp not ISO 8601' => [static fn (array $d): array => ['preparedAt' => '28.10.2026'] + $d, 'invalid "preparedAt" timestamp'];
        yield 'address not an object' => [static fn (array $d): array => ['address' => 'Musterstraße 1'] + $d, 'invalid address'];
        yield 'address field missing' => [static fn (array $d): array => ['address' => ['street' => 'Musterstraße 1']] + $d, 'field "postalCode" must be a string'];
        yield 'feature not an object' => [static fn (array $d): array => ['isochrones' => ['type' => 'FeatureCollection', 'features' => ['polygon']]] + $d, 'contain an invalid feature'];
        yield 'slug not a string' => [static fn (array $d): array => ['slug' => 42] + $d, 'field "slug" must be a string'];
        yield 'latitude not a number' => [static fn (array $d): array => ['coordinates' => ['lat' => '50.5', 'lng' => 8.25]] + $d, 'coordinate "lat" must be a number'];
    }

    public function testRejectsEmptyIsochrones(): void
    {
        $data = $this->location()->toArray();
        $data['isochrones'] = ['type' => 'FeatureCollection', 'features' => []];

        $this->expectException(\InvalidArgumentException::class);
        PreparedLocation::fromArray($data);
    }

    private function location(): PreparedLocation
    {
        return new PreparedLocation(
            slug: 'musterstadt',
            label: 'Klinikum Musterstadt',
            address: new HospitalGeocodeAddress('Musterstraße 1', '12345', 'Musterstadt', 'DE'),
            latitude: 50.5,
            longitude: 8.25,
            isochrones: IsochroneOrigin::withCoordinates($this->geojson(), 50.5, 8.25),
            preparedAt: new \DateTimeImmutable('2026-10-28T12:00:00+00:00'),
        );
    }

    /**
     * @return array{type: string, features: list<array<string, mixed>>}
     */
    private function geojson(): array
    {
        return [
            'type' => 'FeatureCollection',
            'features' => [
                ['type' => 'Feature', 'properties' => ['value' => 600], 'geometry' => ['type' => 'Polygon', 'coordinates' => []]],
            ],
        ];
    }
}
