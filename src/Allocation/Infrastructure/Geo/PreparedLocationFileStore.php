<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Geo;

use App\Allocation\Application\Contracts\PreparedLocationStoreInterface;
use App\Allocation\Application\Hospital\PreparedLocation\PreparedLocation;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Stores prepared locations as one JSON file per slug under var/geo/prepared-locations.
 * The files name real places and are never committed.
 *
 * @psalm-suppress UnusedClass Wired via PreparedLocationStoreInterface autowiring.
 */
final readonly class PreparedLocationFileStore implements PreparedLocationStoreInterface
{
    public function __construct(
        #[Autowire(param: 'app.prepared_locations_dir')]
        private string $baseDirectory,
    ) {
    }

    #[\Override]
    public function find(string $slug): ?PreparedLocation
    {
        if (!PreparedLocation::isValidSlug($slug)) {
            return null;
        }

        return $this->read($this->pathFor($slug));
    }

    #[\Override]
    public function exists(string $slug): bool
    {
        return PreparedLocation::isValidSlug($slug) && is_file($this->pathFor($slug));
    }

    #[\Override]
    public function save(PreparedLocation $location): void
    {
        if (!is_dir($this->baseDirectory) && !mkdir($this->baseDirectory, 0775, true) && !is_dir($this->baseDirectory)) {
            throw new \RuntimeException(sprintf('Unable to create prepared location directory "%s".', $this->baseDirectory));
        }

        $json = json_encode($location->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (false === file_put_contents($this->pathFor($location->slug), $json)) {
            throw new \RuntimeException(sprintf('Unable to write prepared location "%s".', $location->slug));
        }
    }

    #[\Override]
    public function all(): array
    {
        $paths = glob($this->baseDirectory.'/*.json');
        if (false === $paths) {
            return [];
        }

        sort($paths);
        $locations = [];
        foreach ($paths as $path) {
            $location = $this->read($path);
            if ($location instanceof PreparedLocation) {
                $locations[] = $location;
            }
        }

        return $locations;
    }

    public function pathFor(string $slug): string
    {
        return $this->baseDirectory.'/'.$slug.'.json';
    }

    private function read(string $path): ?PreparedLocation
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (false === $raw || '' === $raw) {
            return null;
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!\is_array($data)) {
                return null;
            }

            $location = PreparedLocation::fromArray($data);
        } catch (\JsonException|\InvalidArgumentException) {
            return null;
        }

        return basename($path, '.json') === $location->slug ? $location : null;
    }
}
