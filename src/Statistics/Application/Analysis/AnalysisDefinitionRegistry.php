<?php

declare(strict_types=1);

namespace App\Statistics\Application\Analysis;

use App\Statistics\Application\Analysis\Exception\UnknownAnalysisDefinitionException;

/**
 * @phpstan-import-type AnalysisView from AnalysisDefinitionInterface
 * @phpstan-import-type AnalysisChartType from AnalysisDefinitionInterface
 */
final class AnalysisDefinitionRegistry
{
    /** @var array<string, AnalysisDefinitionInterface> */
    private array $byKey = [];

    /**
     * @param iterable<AnalysisDefinitionInterface> $definitions
     */
    public function __construct(iterable $definitions)
    {
        foreach ($definitions as $definition) {
            $this->byKey[$definition->key()] = $definition;
        }
    }

    /**
     * @return list<AnalysisDefinitionInterface>
     */
    public function all(): array
    {
        return array_values($this->byKey);
    }

    public function get(string $key): ?AnalysisDefinitionInterface
    {
        return $this->byKey[$key] ?? null;
    }

    public function getOrFirst(string $key): AnalysisDefinitionInterface
    {
        if (isset($this->byKey[$key])) {
            return $this->byKey[$key];
        }

        $first = reset($this->byKey);
        if (false === $first) {
            throw new UnknownAnalysisDefinitionException('No analysis definitions registered.');
        }

        return $first;
    }
}
