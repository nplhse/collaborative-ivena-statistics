<?php

declare(strict_types=1);

namespace App\Statistics\Application\TopList;

use App\Statistics\Application\TopList\Exception\UnknownTopListDefinitionException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class TopListDefinitionRegistry
{
    /** @var array<string, TopListDefinitionInterface> */
    private array $byKey = [];

    /**
     * @param iterable<TopListDefinitionInterface> $definitions
     */
    public function __construct(
        #[AutowireIterator('app.statistics.top_list_definition')]
        iterable $definitions,
    ) {
        foreach ($definitions as $definition) {
            $this->byKey[$definition->key()] = $definition;
        }
    }

    /**
     * @return list<TopListDefinitionInterface>
     */
    public function all(): array
    {
        return array_values($this->byKey);
    }

    public function get(string $key): ?TopListDefinitionInterface
    {
        return $this->byKey[$key] ?? null;
    }

    public function getOrFirst(string $key): TopListDefinitionInterface
    {
        if (isset($this->byKey[$key])) {
            return $this->byKey[$key];
        }

        $first = reset($this->byKey);
        if (false === $first) {
            throw new UnknownTopListDefinitionException('No top list definitions registered.');
        }

        return $first;
    }
}
