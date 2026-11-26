<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Infrastructure\Http;

use App\Allocation\Domain\Enum\DistrictBoundaryFetchFailure;
use App\Allocation\Infrastructure\Http\BkgDistrictBoundaryClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BkgDistrictBoundaryClientTest extends TestCase
{
    private const array GEOMETRY = ['type' => 'Polygon', 'coordinates' => [[[8.6, 49.9], [8.7, 49.9], [8.7, 50.0], [8.6, 49.9]]]];

    public function testRequestsOneDistrictInWgs84AndKeepsItsProvenance(): void
    {
        $requestedUrl = '';
        $http = new MockHttpClient(function (string $method, string $url) use (&$requestedUrl): MockResponse {
            $requestedUrl = urldecode($url);

            return new MockResponse((string) json_encode(['features' => [[
                'properties' => ['ags' => '06411', 'bez' => 'Kreisfreie Stadt'],
                'geometry' => self::GEOMETRY,
            ]]]));
        });

        $outcome = $this->client($http)->fetch('06411');

        self::assertEquals(self::GEOMETRY, $outcome->geometry);
        self::assertSame('Kreisfreie Stadt', $outcome->kind);
        self::assertSame('bkg', $outcome->provider);
        self::assertSame('dl-de/by-2-0', $outcome->licence);
        self::assertSame('© BKG (2026) dl-de/by-2-0', $outcome->attribution);
        self::assertStringContainsString('SRSNAME=EPSG:4326', $requestedUrl);
        self::assertStringContainsString('<fes:Literal>06411</fes:Literal>', $requestedUrl);
        self::assertStringContainsString('<fes:ValueReference>gf</fes:ValueReference><fes:Literal>4</fes:Literal>', $requestedUrl);
    }

    /**
     * @return iterable<string, array{MockResponse, DistrictBoundaryFetchFailure}>
     */
    public static function failures(): iterable
    {
        yield 'unknown key' => [new MockResponse('{"features":[]}'), DistrictBoundaryFetchFailure::NotFound];
        yield 'rate limit' => [new MockResponse('', ['http_code' => 429]), DistrictBoundaryFetchFailure::RateLimited];
        yield 'server error' => [new MockResponse('', ['http_code' => 503]), DistrictBoundaryFetchFailure::Unavailable];
        yield 'not json' => [new MockResponse('<ows:ExceptionReport/>'), DistrictBoundaryFetchFailure::Unavailable];
        yield 'no feature list' => [new MockResponse('{"type":"x"}'), DistrictBoundaryFetchFailure::Unavailable];
        yield 'two features' => [new MockResponse('{"features":[{},{}]}'), DistrictBoundaryFetchFailure::InvalidGeometry];
        yield 'another district' => [new MockResponse('{"features":[{"properties":{"ags":"06412"},"geometry":{"type":"Polygon"}}]}'), DistrictBoundaryFetchFailure::InvalidGeometry];
    }

    #[DataProvider('failures')]
    public function testReportsAFailure(MockResponse $response, DistrictBoundaryFetchFailure $failure): void
    {
        $outcome = $this->client(new MockHttpClient($response))->fetch('06411');

        self::assertNull($outcome->geometry);
        self::assertSame($failure, $outcome->failure);
    }

    public function testATransportErrorMeansUnavailable(): void
    {
        $http = new MockHttpClient(static fn (): never => throw new TransportException('offline'));

        self::assertSame(DistrictBoundaryFetchFailure::Unavailable, $this->client($http)->fetch('06411')->failure);
    }

    private function client(MockHttpClient $http): BkgDistrictBoundaryClient
    {
        return new BkgDistrictBoundaryClient($http, new NullLogger(), new MockClock('2026-11-23 12:00:00'));
    }
}
