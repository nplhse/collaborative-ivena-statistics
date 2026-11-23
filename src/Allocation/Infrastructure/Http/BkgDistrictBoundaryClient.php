<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Http;

use App\Allocation\Application\Contracts\DistrictBoundaryClientInterface;
use App\Allocation\Application\Geo\DTO\DistrictBoundaryOutcome;
use App\Allocation\Domain\Enum\DistrictBoundaryFetchFailure;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * District boundaries from the WFS of the BKG (VG250, dl-de/by-2-0). `gf = 4` selects the land area of a district.
 *
 * @psalm-suppress UnusedClass Wired via DistrictBoundaryClientInterface autowiring.
 */
final readonly class BkgDistrictBoundaryClient implements DistrictBoundaryClientInterface
{
    private const string ENDPOINT = 'https://sgx.geodatenzentrum.de/wfs_vg250';
    private const int REQUEST_TIMEOUT_SECONDS = 30;
    private const string PROVIDER = 'bkg';
    private const string LICENCE = 'dl-de/by-2-0';

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function fetch(string $ags): DistrictBoundaryOutcome
    {
        try {
            $response = $this->httpClient->request('GET', self::ENDPOINT, [
                'query' => [
                    'SERVICE' => 'WFS',
                    'VERSION' => '2.0.0',
                    'REQUEST' => 'GetFeature',
                    'TYPENAMES' => 'vg250:vg250_krs',
                    'OUTPUTFORMAT' => 'application/json',
                    'SRSNAME' => 'EPSG:4326',
                    'PROPERTYNAME' => 'ags,gen,bez,sn_l,geom',
                    'FILTER' => $this->filter($ags),
                ],
                'timeout' => self::REQUEST_TIMEOUT_SECONDS,
            ]);

            $status = $response->getStatusCode();
            if (429 === $status) {
                return DistrictBoundaryOutcome::failed(DistrictBoundaryFetchFailure::RateLimited);
            }
            if ($status >= 300) {
                $this->logger->warning('BKG district boundary request failed.', ['status' => $status, 'ags' => $ags]);

                return DistrictBoundaryOutcome::failed(DistrictBoundaryFetchFailure::Unavailable);
            }

            return $this->outcomeFromPayload($ags, $response->toArray(false));
        } catch (TransportExceptionInterface|HttpExceptionInterface|DecodingExceptionInterface $exception) {
            $this->logger->warning('BKG district boundary request failed.', ['exception' => $exception, 'ags' => $ags]);

            return DistrictBoundaryOutcome::failed(DistrictBoundaryFetchFailure::Unavailable);
        }
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function outcomeFromPayload(string $ags, array $payload): DistrictBoundaryOutcome
    {
        $features = $payload['features'] ?? null;
        if (!\is_array($features)) {
            return DistrictBoundaryOutcome::failed(DistrictBoundaryFetchFailure::Unavailable);
        }
        if ([] === $features) {
            return DistrictBoundaryOutcome::failed(DistrictBoundaryFetchFailure::NotFound);
        }

        $feature = 1 === \count($features) ? reset($features) : null;
        if (!\is_array($feature) || !\is_array($feature['geometry'] ?? null) || !\is_array($feature['properties'] ?? null)) {
            return DistrictBoundaryOutcome::failed(DistrictBoundaryFetchFailure::InvalidGeometry);
        }
        if ($ags !== ($feature['properties']['ags'] ?? null)) {
            return DistrictBoundaryOutcome::failed(DistrictBoundaryFetchFailure::InvalidGeometry);
        }

        $kind = $feature['properties']['bez'] ?? null;

        /** @var array<string, mixed> $geometry */
        $geometry = $feature['geometry'];

        return DistrictBoundaryOutcome::found(
            $geometry,
            \is_string($kind) && '' !== $kind ? $kind : null,
            self::PROVIDER,
            self::LICENCE,
            sprintf('© BKG (%s) %s', $this->clock->now()->format('Y'), self::LICENCE),
        );
    }

    private function filter(string $ags): string
    {
        return sprintf(
            '<fes:Filter xmlns:fes="http://www.opengis.net/fes/2.0"><fes:And><fes:PropertyIsEqualTo><fes:ValueReference>ags</fes:ValueReference><fes:Literal>%s</fes:Literal></fes:PropertyIsEqualTo><fes:PropertyIsEqualTo><fes:ValueReference>gf</fes:ValueReference><fes:Literal>4</fes:Literal></fes:PropertyIsEqualTo></fes:And></fes:Filter>',
            htmlspecialchars($ags, \ENT_XML1 | \ENT_QUOTES),
        );
    }
}
