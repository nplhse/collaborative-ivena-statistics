<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Infrastructure\Http;

use App\Allocation\Infrastructure\Http\OpenRouteServiceIsochroneClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenRouteServiceIsochroneClientTest extends TestCase
{
    public function testReturnsNullWhenApiKeyIsMissing(): void
    {
        $httpClient = new MockHttpClient(static function (): MockResponse {
            self::fail('OpenRouteService must not be called without an API key.');
        });
        $client = new OpenRouteServiceIsochroneClient(
            $httpClient,
            new NullLogger(),
            '',
        );

        self::assertFalse($client->hasApiKey());
        $outcome = $client->fetchDestinationIsochrones(50.1109, 8.6821);
        self::assertTrue($outcome->requestFailed);
        self::assertNull($outcome->geojson);
    }

    public function testMapsOpenRouteServicePayloadToSlimGeoJsonWithoutCaching(): void
    {
        $requests = 0;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            ++$requests;
            self::assertSame('POST', $method);
            self::assertStringContainsString('/v2/isochrones/driving-car', $url);
            $body = (string) ($options['body'] ?? '');
            self::assertStringContainsString('destination', $body);
            self::assertStringContainsString('3000', $body);
            self::assertStringContainsString('[300,600,900,1200,1500,1800,2100,2400,2700,3000]', str_replace(' ', '', $body));
            self::assertStringNotContainsString('interval', $body);

            return new MockResponse(json_encode([
                'type' => 'FeatureCollection',
                'features' => [
                    [
                        'type' => 'Feature',
                        'properties' => ['value' => 300, 'group_index' => 0, 'center' => [8.68, 50.11]],
                        'geometry' => [
                            'type' => 'Polygon',
                            'coordinates' => [[[8.6, 50.1], [8.7, 50.1], [8.7, 50.2], [8.6, 50.2], [8.6, 50.1]]],
                        ],
                    ],
                    [
                        'type' => 'Feature',
                        'properties' => ['value' => 600],
                        'geometry' => [
                            'type' => 'Polygon',
                            'coordinates' => [[[8.5, 50.0], [8.8, 50.0], [8.8, 50.3], [8.5, 50.3], [8.5, 50.0]]],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
        $client = new OpenRouteServiceIsochroneClient(
            $httpClient,
            new NullLogger(),
            'test-key',
        );

        $first = $client->fetchDestinationIsochrones(50.1109, 8.6821);
        $second = $client->fetchDestinationIsochrones(50.1109, 8.6821);

        self::assertTrue($client->hasApiKey());
        self::assertSame(2, $requests);
        self::assertNotNull($first->geojson);
        self::assertSame('FeatureCollection', $first->geojson['type']);
        self::assertCount(2, $first->geojson['features']);
        self::assertSame(['value' => 300], $first->geojson['features'][0]['properties']);
        self::assertSame($first->geojson, $second->geojson);
    }

    public function testReturnsNullWhenOpenRouteServiceRespondsWithError(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"error":"denied"}', ['http_code' => 401]),
        ]);
        $client = new OpenRouteServiceIsochroneClient(
            $httpClient,
            new NullLogger(),
            'test-key',
        );

        $outcome = $client->fetchDestinationIsochrones(50.1109, 8.6821);
        self::assertTrue($outcome->requestFailed);
        self::assertFalse($outcome->rateLimited);
    }

    public function testReturnsRateLimitedForTooManyRequests(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"error":"Rate limit exceeded"}', [
                'http_code' => 429,
                'response_headers' => ['retry-after' => '12'],
            ]),
        ]);
        $client = new OpenRouteServiceIsochroneClient(
            $httpClient,
            new NullLogger(),
            'test-key',
        );

        $outcome = $client->fetchDestinationIsochrones(50.1109, 8.6821);
        self::assertTrue($outcome->rateLimited);
        self::assertSame(12, $outcome->retryAfterSeconds);
        self::assertNull($outcome->geojson);
    }

    public function testReturnsRateLimitedForQuotaExceeded(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"error":"Quota exceeded"}', ['http_code' => 403]),
        ]);
        $client = new OpenRouteServiceIsochroneClient(
            $httpClient,
            new NullLogger(),
            'test-key',
        );

        $outcome = $client->fetchDestinationIsochrones(50.1109, 8.6821);
        self::assertTrue($outcome->rateLimited);
        self::assertNull($outcome->geojson);
    }

    public function testReturnsFailedWhenPayloadHasNoFeatures(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"type":"FeatureCollection","features":[]}', ['http_code' => 200]),
        ]);
        $client = new OpenRouteServiceIsochroneClient(
            $httpClient,
            new NullLogger(),
            'test-key',
        );

        $outcome = $client->fetchDestinationIsochrones(50.1109, 8.6821);
        self::assertTrue($outcome->requestFailed);
    }

    public function testReturnsFailedWhenTransportThrows(): void
    {
        $httpClient = new MockHttpClient(static function (): MockResponse {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('timeout');
        });
        $client = new OpenRouteServiceIsochroneClient($httpClient, new NullLogger(), 'test-key');

        $outcome = $client->fetchDestinationIsochrones(50.1109, 8.6821);
        self::assertTrue($outcome->requestFailed);
    }

    public function testSkipsInvalidFeaturesAndReturnsFailedWhenNoneRemain(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse(json_encode([
                'type' => 'FeatureCollection',
                'features' => [
                    'not-an-array',
                    [
                        'type' => 'Feature',
                        'properties' => ['value' => 300],
                    ],
                    [
                        'type' => 'Feature',
                        'properties' => [],
                        'geometry' => ['type' => 'Polygon', 'coordinates' => []],
                    ],
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);
        $client = new OpenRouteServiceIsochroneClient($httpClient, new NullLogger(), 'test-key');

        $outcome = $client->fetchDestinationIsochrones(50.1109, 8.6821);
        self::assertTrue($outcome->requestFailed);
    }
}
