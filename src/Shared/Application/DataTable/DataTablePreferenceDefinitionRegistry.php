<?php

declare(strict_types=1);

namespace App\Shared\Application\DataTable;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class DataTablePreferenceDefinitionRegistry
{
    /** @var array<string, DataTablePreferenceSchema> */
    private array $schemas = [];

    /**
     * @param iterable<DataTablePreferenceDefinitionInterface> $definitions
     *
     * @psalm-suppress PossiblyUnusedMethod Instantiated by the dependency injection container.
     */
    public function __construct(
        #[AutowireIterator('app.data_table_preference_definition')]
        iterable $definitions,
    ) {
        foreach ($definitions as $definition) {
            $schema = $definition->preferenceSchema();
            if (isset($this->schemas[$schema->key])) {
                throw new \LogicException(sprintf('Duplicate DataTable preference key "%s".', $schema->key));
            }
            $this->schemas[$schema->key] = $schema;
        }
    }

    public function get(string $key): ?DataTablePreferenceSchema
    {
        return $this->schemas[$key] ?? null;
    }
}
