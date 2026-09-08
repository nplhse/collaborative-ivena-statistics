<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Http;

use App\Allocation\Application\Contracts\HospitalIsochroneClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OpenRouteService destination isochrones (driving-car, 5–50 minutes). Used by the fetch command only.
 *
 * @psalm-suppress UnusedClass Wired via HospitalIsochroneClientInterface autowiring.
 */
final readonly class OpenRouteServiceIsochroneClient implements HospitalIsochroneClientInterface
{
    private const string ENDPOINT = 'https://api.openrouteservice.org/v2/isochrones/driving-car';
    /** @var list<int> Five-minute bands; public ORS allows at most 10 ranges per request. */
    private const array RANGE_SECONDS = [300, 600, 900, 1200, 1500, 1800, 2100, 2400, 2700, 3000];
    private const int REQUEST_TIMEOUT_SECONDS = 20;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        #[Autowire(env: 'OPENROUTESERVICE_API_KEY')]
        private string $apiKey,
    ) {
    }

    #[\Override]
    public function hasApiKey(): bool
    {
        return '' !== trim($this->apiKey);
    }

    /**
     * @return array{type: string, features: list<array<string, mixed>>}|null
     */
    #[\Override]
    public function fetchDestinationIsochrones(float $latitude, float $longitude): ?array
    {
        if (!$this->hasApiKey()) {
            return null;
        }

        try {
            $response = $this->httpClient->request('POST', self::ENDPOINT, [
                'headers' => [
                    'Authorization' => $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/geo+json, application/json',
                ],
                'json' => [
                    'locations' => [[$longitude, $latitude]],
                    'range_type' => 'time',
                    'range' => self::RANGE_SECONDS,
                    'location_type' => 'destination',
                ],
                'timeout' => self::REQUEST_TIMEOUT_SECONDS,
            ]);

            if ($response->getStatusCode() >= 300) {
                $this->logger->warning('OpenRouteService isochrones request failed.', [
                    'status' => $response->getStatusCode(),
                    'response' => $response->getContent(false),
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                ]);

                return null;
            }

            return $this->reduceGeoJson($response->toArray(false));
        } catch (TransportExceptionInterface|HttpExceptionInterface|DecodingExceptionInterface $exception) {
            $this->logger->warning('OpenRouteService isochrones request failed.', [
                'exception' => $exception,
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{type: string, features: list<array<string, mixed>>}|null
     */
    private function reduceGeoJson(array $payload): ?array
    {
        $rawFeatures = $payload['features'] ?? null;
        if (!\is_array($rawFeatures) || [] === $rawFeatures) {
            return null;
        }

        $features = [];
        foreach ($rawFeatures as $feature) {
            if (!\is_array($feature)) {
                continue;
            }

            $properties = $feature['properties'] ?? null;
            $geometry = $feature['geometry'] ?? null;
            $value = \is_array($properties) ? ($properties['value'] ?? null) : null;
            if (!\is_array($geometry) || (!\is_int($value) && !\is_float($value))) {
                continue;
            }

            $features[] = [
                'type' => 'Feature',
                'properties' => ['value' => (int) $value],
                'geometry' => $geometry,
            ];
        }

        if ([] === $features) {
            return null;
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
        ];
    }
}
