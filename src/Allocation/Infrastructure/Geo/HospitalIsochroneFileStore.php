<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Geo;

use App\Allocation\Application\Contracts\HospitalIsochroneStoreInterface;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\State;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Reads and writes hospital isochrone GeoJSON under var/geo/hospital-isochrones.
 *
 * @psalm-suppress UnusedClass Wired via HospitalIsochroneStoreInterface autowiring.
 */
final readonly class HospitalIsochroneFileStore implements HospitalIsochroneStoreInterface
{
    public function __construct(
        #[Autowire(param: 'app.hospital_isochrones_dir')]
        private string $baseDirectory,
    ) {
    }

    #[\Override]
    public function findForHospital(Hospital $hospital): ?array
    {
        $path = $this->pathFor($hospital);
        if (null === $path || !is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (false === $raw || '' === $raw) {
            return null;
        }

        try {
            $parsed = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($parsed) || ($parsed['type'] ?? null) !== 'FeatureCollection' || !isset($parsed['features']) || !\is_array($parsed['features'])) {
            return null;
        }

        /** @var list<array<string, mixed>> $features */
        $features = [];
        foreach ($parsed['features'] as $feature) {
            if (\is_array($feature)) {
                $features[] = $feature;
            }
        }

        if ([] === $features) {
            return null;
        }

        $result = [
            'type' => 'FeatureCollection',
            'features' => $features,
        ];
        $properties = $this->collectionProperties($parsed);
        if (null !== $properties) {
            $result['properties'] = $properties['properties'];
        }

        return $result;
    }

    #[\Override]
    public function existsForHospital(Hospital $hospital): bool
    {
        $path = $this->pathFor($hospital);

        return null !== $path && is_file($path);
    }

    #[\Override]
    public function writeForHospital(Hospital $hospital, array $geojson): void
    {
        $path = $this->pathFor($hospital);
        if (null === $path) {
            throw new \InvalidArgumentException('Hospital is missing a public id or state id.');
        }

        $directory = \dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create isochrone directory "%s".', $directory));
        }

        $json = json_encode($geojson, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (false === file_put_contents($path, $json)) {
            throw new \RuntimeException(sprintf('Unable to write isochrone file "%s".', $path));
        }
    }

    public function pathFor(Hospital $hospital): ?string
    {
        $state = $hospital->getState();
        $publicId = $hospital->getPublicId();
        if (!$state instanceof State || !$publicId instanceof Uuid) {
            return null;
        }

        $stateId = $state->getId();
        if (null === $stateId) {
            return null;
        }

        return $this->baseDirectory.'/'.$stateId.'/'.$publicId->toRfc4122().'.geojson';
    }

    /**
     * @param array<string, mixed> $parsed
     *
     * @return array{properties: array<string, mixed>}|null
     */
    private function collectionProperties(array $parsed): ?array
    {
        $properties = $parsed['properties'] ?? null;
        if (!\is_array($properties) || [] === $properties) {
            return null;
        }

        return ['properties' => $properties];
    }
}
