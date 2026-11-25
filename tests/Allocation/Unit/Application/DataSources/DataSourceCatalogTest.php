<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\DataSources;

use App\Allocation\Application\DataSources\DataSource;
use App\Allocation\Application\DataSources\DataSourceCatalog;
use PHPUnit\Framework\TestCase;

final class DataSourceCatalogTest extends TestCase
{
    private const string OSM_URL = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
    private const string OSM_NOTE = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>';

    public function testBoundaryVintagesOfOneSourceAreOneEntry(): void
    {
        $sources = new DataSourceCatalog()->all([
            ['provider' => 'legacy_vg250', 'licence' => 'dl-de/by-2-0', 'attribution' => '© BKG (2023) dl-de/by-2-0'],
            ['provider' => 'bkg', 'licence' => 'dl-de/by-2-0', 'attribution' => '© BKG (2025) dl-de/by-2-0'],
            ['provider' => 'bkg', 'licence' => 'dl-de/by-2-0', 'attribution' => '© BKG (2025) dl-de/by-2-0'],
        ], '', '');

        self::assertCount(3, $sources, 'boundaries, geocoding and isochrones');
        self::assertSame('BKG, Verwaltungsgebiete 1:250 000 (VG250)', $sources[0]->name);
        self::assertSame(['data_sources.use.boundaries'], $sources[0]->uses);
        self::assertSame('https://www.govdata.de/dl-de/by-2-0', $sources[0]->licenceUrl);
        self::assertSame(['© BKG (2023) dl-de/by-2-0', '© BKG (2025) dl-de/by-2-0'], $sources[0]->attributions);
    }

    public function testAnUnknownProviderAndLicenceAreShownWithoutLinks(): void
    {
        $source = new DataSourceCatalog()->all([
            ['provider' => 'other', 'licence' => 'custom', 'attribution' => '© Other'],
        ], '', '')[0];

        self::assertSame('other', $source->name);
        self::assertNull($source->url);
        self::assertSame('custom', $source->licence);
        self::assertNull($source->licenceUrl);
    }

    public function testWithoutBoundariesAndTilesOnlyTheHeigitServicesRemain(): void
    {
        $sources = new DataSourceCatalog()->all([], '', '');

        self::assertSame(['HeiGIT geocoding', 'openrouteservice (HeiGIT)'], array_map(static fn (DataSource $source): ?string => $source->name, $sources));
    }

    public function testGeocodingAndIsochronesCarryTheLicencesOfTheHeigitTerms(): void
    {
        [$geocoding, $isochrones] = new DataSourceCatalog()->all([], '', '');

        self::assertSame(['data_sources.use.geocoding'], $geocoding->uses);
        self::assertSame('ODbL 1.0', $geocoding->licence);
        self::assertSame('© OpenStreetMap contributors', $geocoding->attributions[0]);

        self::assertSame(['data_sources.use.isochrones'], $isochrones->uses);
        self::assertSame('CC BY-SA 4.0', $isochrones->licence);
        self::assertSame('https://creativecommons.org/licenses/by-sa/4.0/', $isochrones->licenceUrl);
        self::assertSame(['© openrouteservice by HeiGIT | Data from OpenStreetMap'], $isochrones->attributions);
        self::assertSame('https://account.heigit.org/info/tos', $isochrones->url);
    }

    public function testOpenStreetMapTilesHavePlainSourceNote(): void
    {
        $tiles = new DataSourceCatalog()->all([], self::OSM_URL, self::OSM_NOTE)[2];

        self::assertSame('OpenStreetMap', $tiles->name);
        self::assertSame('ODbL 1.0', $tiles->licence);
        self::assertSame(['© OpenStreetMap'], $tiles->attributions);
    }

    public function testAConfiguredTileServerKeepsItsAddressOffThePage(): void
    {
        $tiles = new DataSourceCatalog()->all([], 'https://tiles.internal.example/{z}/{x}/{y}.png?key=secret', '<b>Own</b> tiles')[2];

        self::assertNull($tiles->name);
        self::assertNull($tiles->url);
        self::assertNull($tiles->licence);
        self::assertSame(['Own tiles'], $tiles->attributions);
        self::assertStringNotContainsString('secret', serialize($tiles));
    }
}
