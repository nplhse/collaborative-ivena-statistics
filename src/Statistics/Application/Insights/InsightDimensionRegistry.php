<?php

declare(strict_types=1);

namespace App\Statistics\Application\Insights;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class InsightDimensionRegistry
{
    /** @var array<string, InsightDimensionProviderInterface> */
    private array $byKey = [];

    /**
     * @param iterable<InsightDimensionProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('app.statistics.insight_dimension')]
        iterable $providers,
    ) {
        foreach ($providers as $provider) {
            $this->byKey[$provider->key()->value] = $provider;
        }
    }

    public function get(InsightDimensionKey $key): InsightDimensionProviderInterface
    {
        $provider = $this->byKey[$key->value] ?? null;
        if (!$provider instanceof InsightDimensionProviderInterface) {
            throw new \InvalidArgumentException(sprintf('Unknown insight dimension "%s".', $key->value));
        }

        return $provider;
    }

    public function getBySlug(string $slug): ?InsightDimensionProviderInterface
    {
        return $this->byKey[$slug] ?? null;
    }

    /**
     * @return list<InsightDimensionProviderInterface>
     */
    public function all(): array
    {
        $providers = array_values($this->byKey);
        usort(
            $providers,
            static fn (InsightDimensionProviderInterface $a, InsightDimensionProviderInterface $b): int => $a->navOrder() <=> $b->navOrder(),
        );

        return $providers;
    }

    /**
     * @return list<InsightDimensionProviderInterface>
     */
    public function primaryNav(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (InsightDimensionProviderInterface $provider): bool => InsightNavPlacement::Primary === $provider->navPlacement(),
        ));
    }

    /**
     * @return list<InsightDimensionProviderInterface>
     */
    public function overviewTeasers(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (InsightDimensionProviderInterface $provider): bool => !$provider->featuredOnOverview()
                && InsightNavPlacement::Nested !== $provider->navPlacement(),
        ));
    }

    public function featured(): InsightDimensionProviderInterface
    {
        foreach ($this->all() as $provider) {
            if ($provider->featuredOnOverview()) {
                return $provider;
            }
        }

        throw new \LogicException('No featured insight dimension is registered.');
    }
}
