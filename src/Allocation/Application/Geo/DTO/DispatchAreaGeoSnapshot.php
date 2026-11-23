<?php

declare(strict_types=1);

namespace App\Allocation\Application\Geo\DTO;

/**
 * Dispatch areas with a complete shape, without their polygons. A map feature's key is the dispatch area id.
 *
 * @psalm-immutable
 */
final readonly class DispatchAreaGeoSnapshot
{
    /**
     * @param array<array-key, array{state: string, districts: list<string>}> $areas by feature key (numeric keys become integers)
     */
    public function __construct(
        private array $areas = [],
    ) {
    }

    /**
     * @psalm-pure
     */
    public static function keyFor(int $dispatchAreaId): string
    {
        return (string) $dispatchAreaId;
    }

    public function hasShape(string $key): bool
    {
        return isset($this->areas[$key]);
    }

    public function districtLabel(string $key): ?string
    {
        $districts = $this->areas[$key]['districts'] ?? [];

        return [] === $districts ? null : implode(', ', $districts);
    }

    /**
     * The state's name as stored, when at least one of its areas has a shape.
     */
    public function stateWithShapes(string $name): ?string
    {
        foreach ($this->areas as $area) {
            if (0 === strcasecmp(trim($name), $area['state'])) {
                return $area['state'];
            }
        }

        return null;
    }
}
