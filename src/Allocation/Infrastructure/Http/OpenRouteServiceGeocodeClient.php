<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Http;

use App\Allocation\Application\Contracts\HospitalGeocodeClientInterface;
use App\Allocation\Application\Hospital\DTO\HospitalGeocodeMatch;
use App\Allocation\Application\Hospital\DTO\HospitalGeocodeOutcome;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OpenRouteService Pelias structured geocode. Used by the geocode command only.
 *
 * @psalm-suppress UnusedClass Wired via HospitalGeocodeClientInterface autowiring.
 */
final readonly class OpenRouteServiceGeocodeClient implements HospitalGeocodeClientInterface
{
    private const string ENDPOINT = 'https://api.openrouteservice.org/geocode/search/structured';
    private const int REQUEST_TIMEOUT_SECONDS = 20;
    /** @var list<string> */
    private const array USABLE_LAYERS = ['address', 'venue', 'street'];
    /** @var list<string> */
    private const array GERMANY_CODES = ['DE', 'DEU'];

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

    #[\Override]
    public function geocodeAddress(string $street, string $postalCode, string $city, string $country): HospitalGeocodeOutcome
    {
        if (!$this->hasApiKey()) {
            return HospitalGeocodeOutcome::failed();
        }

        $query = [
            'address' => $street,
            'country' => $country,
            'size' => 1,
        ];
        if ('' !== $postalCode) {
            $query['postalcode'] = $postalCode;
        }
        if ('' !== $city) {
            $query['locality'] = $city;
        }

        try {
            $response = $this->httpClient->request('GET', self::ENDPOINT, [
                'headers' => [
                    'Authorization' => $this->apiKey,
                    'Accept' => 'application/geo+json, application/json',
                ],
                'query' => $query,
                'timeout' => self::REQUEST_TIMEOUT_SECONDS,
            ]);

            $status = $response->getStatusCode();
            if ($status >= 300) {
                $body = $response->getContent(false);
                $this->logger->warning('OpenRouteService geocode request failed.', [
                    'status' => $status,
                    'response' => $body,
                    'street' => $street,
                    'postalCode' => $postalCode,
                    'city' => $city,
                ]);

                if (OpenRouteServiceRateLimit::detected($status, $body)) {
                    return HospitalGeocodeOutcome::rateLimited(
                        OpenRouteServiceRateLimit::retryAfterSeconds($response),
                    );
                }

                return HospitalGeocodeOutcome::failed();
            }

            return $this->outcomeFromPayload($response->toArray(false));
        } catch (TransportExceptionInterface|HttpExceptionInterface|DecodingExceptionInterface $exception) {
            $this->logger->warning('OpenRouteService geocode request failed.', [
                'exception' => $exception,
                'street' => $street,
                'postalCode' => $postalCode,
                'city' => $city,
            ]);

            return HospitalGeocodeOutcome::failed();
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function outcomeFromPayload(array $payload): HospitalGeocodeOutcome
    {
        $features = $payload['features'] ?? null;
        if (!\is_array($features) || [] === $features || !\is_array($features[0] ?? null)) {
            return HospitalGeocodeOutcome::unusableMatch();
        }

        /** @var array<string, mixed> $feature */
        $feature = $features[0];
        $properties = $feature['properties'] ?? null;
        $geometry = $feature['geometry'] ?? null;
        if (!\is_array($properties) || !\is_array($geometry)) {
            return HospitalGeocodeOutcome::unusableMatch();
        }

        $layer = $properties['layer'] ?? '';
        if (!\is_string($layer) || !\in_array($layer, self::USABLE_LAYERS, true)) {
            return HospitalGeocodeOutcome::unusableMatch();
        }

        if (!$this->isGermany($properties)) {
            return HospitalGeocodeOutcome::unusableMatch();
        }

        $coordinates = $geometry['coordinates'] ?? null;
        if (!\is_array($coordinates) || !isset($coordinates[0], $coordinates[1])) {
            return HospitalGeocodeOutcome::unusableMatch();
        }

        $longitude = $coordinates[0];
        $latitude = $coordinates[1];
        if ((!\is_int($longitude) && !\is_float($longitude)) || (!\is_int($latitude) && !\is_float($latitude))) {
            return HospitalGeocodeOutcome::unusableMatch();
        }

        $label = $properties['label'] ?? '';
        if (!\is_string($label) || '' === $label) {
            $label = trim($properties['name'] ?? '') ?: $layer;
        }

        return HospitalGeocodeOutcome::match(new HospitalGeocodeMatch(
            latitude: (float) $latitude,
            longitude: (float) $longitude,
            label: $label,
            layer: $layer,
        ));
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function isGermany(array $properties): bool
    {
        foreach (['country_a', 'country_code', 'country'] as $key) {
            $value = $properties[$key] ?? null;
            if (!\is_string($value) || '' === $value) {
                continue;
            }

            $normalized = strtoupper($value);
            if (\in_array($normalized, self::GERMANY_CODES, true) || 'GERMANY' === $normalized || 'DEUTSCHLAND' === $normalized) {
                return true;
            }
        }

        return false;
    }
}
