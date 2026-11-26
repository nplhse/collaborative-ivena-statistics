<?php

declare(strict_types=1);

namespace App\Allocation\Application\DataSources;

/**
 * The geographic data sources of the installation (#1516). District boundaries come from the provenance stored with
 * the geometries; a new external source that is not stored with provenance is added here.
 */
final class DataSourceCatalog
{
    private const string VG250 = 'BKG, Verwaltungsgebiete 1:250 000 (VG250)';
    private const string VG250_URL = 'https://gdz.bkg.bund.de/index.php/default/verwaltungsgebiete-1-250-000-stand-01-01-vg250-01-01.html';
    private const array BOUNDARY_PROVIDERS = ['bkg' => self::VG250, 'legacy_vg250' => self::VG250, 'demo' => self::VG250];

    private const string ODBL = 'ODbL 1.0';
    private const string ODBL_URL = 'https://opendatacommons.org/licenses/odbl/1-0/';
    private const string CC_BY_SA = 'CC BY-SA 4.0';
    private const string CC_BY_SA_URL = 'https://creativecommons.org/licenses/by-sa/4.0/';
    private const string HEIGIT_TERMS_URL = 'https://account.heigit.org/info/tos';
    private const array LICENCE_URLS = ['dl-de/by-2-0' => 'https://www.govdata.de/dl-de/by-2-0', self::ODBL => self::ODBL_URL];

    private const string OSM_TILE_HOST = 'tile.openstreetmap.org';

    /**
     * @param list<array{provider: string|null, licence: string|null, attribution: string}> $boundaries
     *
     * @return list<DataSource>
     */
    public function all(array $boundaries, string $tileUrl, string $tileAttribution): array
    {
        $sources = $this->boundarySources($boundaries);
        // Licences and source notes as required by the HeiGIT terms of service (#1649).
        $sources[] = new DataSource(
            'HeiGIT geocoding',
            self::HEIGIT_TERMS_URL,
            ['data_sources.use.geocoding'],
            self::ODBL,
            self::ODBL_URL,
            ['© OpenStreetMap contributors', 'OpenAddresses, GeoNames (CC BY 4.0), Who’s On First'],
        );
        $sources[] = new DataSource(
            'openrouteservice (HeiGIT)',
            self::HEIGIT_TERMS_URL,
            ['data_sources.use.isochrones'],
            self::CC_BY_SA,
            self::CC_BY_SA_URL,
            ['© openrouteservice by HeiGIT | Data from OpenStreetMap'],
        );

        $tiles = $this->tileSource($tileUrl, $tileAttribution);
        if ($tiles instanceof DataSource) {
            $sources[] = $tiles;
        }

        return $sources;
    }

    /**
     * One entry per source and licence, with all its source notes, for example of two vintages.
     *
     * @param list<array{provider: string|null, licence: string|null, attribution: string}> $boundaries
     *
     * @return list<DataSource>
     */
    private function boundarySources(array $boundaries): array
    {
        $grouped = [];
        foreach ($boundaries as $row) {
            $name = self::BOUNDARY_PROVIDERS[$row['provider'] ?? ''] ?? (string) $row['provider'];
            $key = $name.'|'.(string) $row['licence'];
            $grouped[$key] ??= ['name' => $name, 'licence' => $row['licence'], 'attributions' => []];
            if (!\in_array($row['attribution'], $grouped[$key]['attributions'], true)) {
                $grouped[$key]['attributions'][] = $row['attribution'];
            }
        }

        $sources = [];
        foreach ($grouped as $source) {
            $sources[] = new DataSource(
                $source['name'],
                self::VG250 === $source['name'] ? self::VG250_URL : null,
                ['data_sources.use.boundaries'],
                $source['licence'],
                self::LICENCE_URLS[$source['licence'] ?? ''] ?? null,
                $source['attributions'],
            );
        }

        return $sources;
    }

    /**
     * The tile server's address stays out of the page; only its source note is shown.
     */
    private function tileSource(string $url, string $attribution): ?DataSource
    {
        if ('' === trim($url)) {
            return null;
        }

        $note = trim(html_entity_decode(strip_tags($attribution), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
        $attributions = '' === $note ? [] : [$note];
        if (str_contains($url, self::OSM_TILE_HOST)) {
            return new DataSource('OpenStreetMap', 'https://www.openstreetmap.org/copyright', ['data_sources.use.tiles'], self::ODBL, self::ODBL_URL, $attributions);
        }

        return new DataSource(null, null, ['data_sources.use.tiles'], null, null, $attributions);
    }
}
