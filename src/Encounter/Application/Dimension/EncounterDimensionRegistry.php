<?php

declare(strict_types=1);

namespace App\Encounter\Application\Dimension;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * All encounter schemas known to the server, by schema name (extension point `encounter.dimension`).
 *
 * @psalm-suppress UnusedClass Extension point of #1047; the encounter import (#1048) is its first consumer.
 */
final readonly class EncounterDimensionRegistry
{
    /** @var array<string, EncounterDimensionInterface> */
    private array $dimensions;

    /**
     * @param iterable<EncounterDimensionInterface> $dimensions
     */
    public function __construct(
        #[AutowireIterator(EncounterDimensionInterface::TAG)]
        iterable $dimensions,
    ) {
        $bySchema = [];
        foreach ($dimensions as $dimension) {
            $schema = $dimension->schema();
            if (isset($bySchema[$schema])) {
                throw new \LogicException(sprintf('Encounter schema "%s" is registered twice (%s, %s).', $schema, $bySchema[$schema]::class, $dimension::class));
            }

            $bySchema[$schema] = $dimension;
        }

        ksort($bySchema);
        $this->dimensions = $bySchema;
    }

    public function has(string $schema): bool
    {
        return isset($this->dimensions[$schema]);
    }

    /**
     * @throws \InvalidArgumentException for an unknown schema
     */
    public function get(string $schema): EncounterDimensionInterface
    {
        return $this->dimensions[$schema] ?? throw new \InvalidArgumentException(sprintf('Unknown encounter schema "%s".', $schema));
    }

    /**
     * @return list<string>
     */
    public function schemas(): array
    {
        return array_keys($this->dimensions);
    }
}
