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
