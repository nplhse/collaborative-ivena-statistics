<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Hospital\PreparedLocation;

use App\Allocation\Application\Contracts\PreparedLocationStoreInterface;
use App\Allocation\Application\Hospital\PreparedLocation\PreparedLocation;

/**
 * @internal
 */
final class InMemoryPreparedLocationStore implements PreparedLocationStoreInterface
{
    /** @var array<string, PreparedLocation> */
    private array $locations = [];

    #[\Override]
    public function find(string $slug): ?PreparedLocation
    {
        return $this->locations[$slug] ?? null;
    }

    #[\Override]
    public function exists(string $slug): bool
    {
        return isset($this->locations[$slug]);
    }

    #[\Override]
    public function save(PreparedLocation $location): void
    {
        $this->locations[$location->slug] = $location;
    }

    #[\Override]
    public function all(): array
    {
        ksort($this->locations);

        return array_values($this->locations);
    }
}
