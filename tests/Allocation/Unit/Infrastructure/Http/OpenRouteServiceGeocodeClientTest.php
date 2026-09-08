<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Infrastructure\Http;

use App\Allocation\Infrastructure\Http\OpenRouteServiceGeocodeClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenRouteServiceGeocodeClientTest extends TestCase
{
    public function testReturnsFailedWhenApiKeyIsMissing(): void
    {
        $httpClient = new MockHttpClient(static function (): MockResponse {
            self::fail('OpenRouteService must not be called without an API key.');
        });
        $client = new OpenRouteServiceGeocodeClient($httpClient, new NullLogger(), '');

        self::assertFalse($client->hasApiKey());
        $outcome = $client->geocodeAddress('Mönchebergstraße 41-43', '34125', 'Kassel', 'DE');
        self::assertTrue($outcome->requestFailed);
        self::assertNull($outcome->match);
    }

    public function testMapsStructuredAddressMatch(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            self::assertStringContainsString('/geocode/search/structured', $url);
            $query = $options['query'] ?? [];
            self::assertSame('Mönchebergstraße 41-43', $query['address'] ?? null);
            self::assertSame('34125', $query['postalcode'] ?? null);
            self::assertSame('Kassel', $query['locality'] ?? null);
            self::assertSame('DE', $query['country'] ?? null);
            self::assertSame(1, $query['size'] ?? null);

            return new MockResponse(json_encode([
                'type' => 'FeatureCollection',
                'features' => [
                    [
                        'type' => 'Feature',
                        'geometry' => ['type' => 'Point', 'coordinates' => [9.5081, 51.3224]],
                        'properties' => [
                            'layer' => 'address',
                            'label' => 'Mönchebergstraße 41-43, 34125 Kassel, Germany',
                            'country_a' => 'DEU',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
        $client = new OpenRouteServiceGeocodeClient($httpClient, new NullLogger(), 'test-key');

        $outcome = $client->geocodeAddress('Mönchebergstraße 41-43', '34125', 'Kassel', 'DE');
        self::assertTrue($client->hasApiKey());
        self::assertFalse($outcome->requestFailed);
        self::assertNotNull($outcome->match);
        self::assertSame(51.3224, $outcome->match->latitude);
        self::assertSame(9.5081, $outcome->match->longitude);
        self::assertSame('address', $outcome->match->layer);
    }

    public function testRejectsCoarseLocalityLayer(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse(json_encode([
                'type' => 'FeatureCollection',
                'features' => [
                    [
                        'type' => 'Feature',
                        'geometry' => ['type' => 'Point', 'coordinates' => [9.4797, 51.3127]],
                        'properties' => [
                            'layer' => 'locality',
                            'label' => 'Kassel, Germany',
                            'country_a' => 'DEU',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);
        $client = new OpenRouteServiceGeocodeClient($httpClient, new NullLogger(), 'test-key');

        $outcome = $client->geocodeAddress('Mönchebergstraße 41-43', '34125', 'Kassel', 'DE');
        self::assertTrue($outcome->unusableMatch);
        self::assertNull($outcome->match);
    }

    public function testReturnsFailedWhenOpenRouteServiceRespondsWithError(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"error":"denied"}', ['http_code' => 401]),
        ]);
        $client = new OpenRouteServiceGeocodeClient($httpClient, new NullLogger(), 'test-key');

        $outcome = $client->geocodeAddress('Mönchebergstraße 41-43', '34125', 'Kassel', 'DE');
        self::assertTrue($outcome->requestFailed);
    }

    public function testReturnsUnusableWhenPayloadHasNoFeatures(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"type":"FeatureCollection","features":[]}', ['http_code' => 200]),
        ]);
        $client = new OpenRouteServiceGeocodeClient($httpClient, new NullLogger(), 'test-key');

        $outcome = $client->geocodeAddress('Mönchebergstraße 41-43', '34125', 'Kassel', 'DE');
        self::assertTrue($outcome->unusableMatch);
    }

    public function testOmitsEmptyPostalCodeAndCityFromQuery(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $query = $options['query'] ?? [];
            self::assertArrayNotHasKey('postalcode', $query);
            self::assertArrayNotHasKey('locality', $query);

            return new MockResponse(json_encode([
                'type' => 'FeatureCollection',
                'features' => [
                    [
                        'type' => 'Feature',
                        'geometry' => ['type' => 'Point', 'coordinates' => [9.5081, 51.3224]],
                        'properties' => [
                            'layer' => 'street',
                            'name' => 'Mönchebergstraße',
                            'country_code' => 'DE',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
        $client = new OpenRouteServiceGeocodeClient($httpClient, new NullLogger(), 'test-key');

        $outcome = $client->geocodeAddress('Mönchebergstraße 41-43', '', '', 'DE');
        self::assertNotNull($outcome->match);
        self::assertSame('street', $outcome->match->layer);
        self::assertSame('Mönchebergstraße', $outcome->match->label);
    }

    public function testRejectsMatchesOutsideGermany(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse(json_encode([
                'type' => 'FeatureCollection',
                'features' => [
                    [
                        'type' => 'Feature',
                        'geometry' => ['type' => 'Point', 'coordinates' => [2.35, 48.85]],
                        'properties' => [
                            'layer' => 'address',
                            'label' => 'Paris',
                            'country' => 'France',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);
        $client = new OpenRouteServiceGeocodeClient($httpClient, new NullLogger(), 'test-key');

        $outcome = $client->geocodeAddress('Rue de Rivoli', '75001', 'Paris', 'FR');
        self::assertTrue($outcome->unusableMatch);
    }

    public function testRejectsNonNumericCoordinates(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse(json_encode([
                'type' => 'FeatureCollection',
                'features' => [
                    [
                        'type' => 'Feature',
                        'geometry' => ['type' => 'Point', 'coordinates' => ['9.5081', '51.3224']],
                        'properties' => [
                            'layer' => 'address',
                            'label' => 'Klinik',
                            'country_a' => 'DEU',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);
        $client = new OpenRouteServiceGeocodeClient($httpClient, new NullLogger(), 'test-key');

        $outcome = $client->geocodeAddress('Mönchebergstraße 41-43', '34125', 'Kassel', 'DE');
        self::assertTrue($outcome->unusableMatch);
    }

    public function testRejectsCoordinatesWithoutLatitudeAndLongitude(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse(json_encode([
                'type' => 'FeatureCollection',
                'features' => [
                    [
                        'type' => 'Feature',
                        'geometry' => ['type' => 'Point', 'coordinates' => [9.5081]],
                        'properties' => [
                            'layer' => 'address',
                            'label' => 'Klinik',
                            'country_a' => 'DEU',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);
        $client = new OpenRouteServiceGeocodeClient($httpClient, new NullLogger(), 'test-key');

        $outcome = $client->geocodeAddress('Mönchebergstraße 41-43', '34125', 'Kassel', 'DE');
        self::assertTrue($outcome->unusableMatch);
    }

    public function testReturnsFailedWhenTransportThrows(): void
    {
        $httpClient = new MockHttpClient(static function (): MockResponse {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('timeout');
        });
        $client = new OpenRouteServiceGeocodeClient($httpClient, new NullLogger(), 'test-key');

        $outcome = $client->geocodeAddress('Mönchebergstraße 41-43', '34125', 'Kassel', 'DE');
        self::assertTrue($outcome->requestFailed);
    }

    public function testReturnsUnusableWhenFeatureIsMissingGeometry(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse(json_encode([
                'type' => 'FeatureCollection',
                'features' => [
                    [
                        'type' => 'Feature',
                        'properties' => [
                            'layer' => 'address',
                            'label' => 'Klinik',
                            'country_a' => 'DEU',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);
        $client = new OpenRouteServiceGeocodeClient($httpClient, new NullLogger(), 'test-key');

        $outcome = $client->geocodeAddress('Mönchebergstraße 41-43', '34125', 'Kassel', 'DE');
        self::assertTrue($outcome->unusableMatch);
    }

    public function testWhitespaceOnlyApiKeyIsTreatedAsMissing(): void
    {
        $client = new OpenRouteServiceGeocodeClient(new MockHttpClient(), new NullLogger(), '   ');

        self::assertFalse($client->hasApiKey());
    }
}
