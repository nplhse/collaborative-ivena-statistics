<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Dto;

final readonly class DistributionPattern
{
    /**
     * @param array<string, mixed> $distributions
     */
    public function __construct(
        public array $distributions,
    ) {
    }

    /**
     * @return array<string, float>
     */
    public function categoricalDistribution(string $key): array
    {
        $distribution = $this->distributions[$key] ?? null;
        if (!\is_array($distribution)) {
            return [];
        }

        /** @var array<string, float> $normalized */
        $normalized = [];
        foreach ($distribution as $label => $weight) {
            if ('_present' === $label || !\is_numeric($weight)) {
                continue;
            }
            $normalized[(string) $label] = (float) $weight;
        }

        return $normalized;
    }

    public function presenceProbability(string $key): ?float
    {
        $distribution = $this->distributions[$key] ?? null;
        if (!\is_array($distribution)) {
            return null;
        }

        if (!isset($distribution['_present']) || !\is_numeric($distribution['_present'])) {
            return null;
        }

        return (float) $distribution['_present'];
    }

    /**
     * A copy with the given flag probabilities replaced.
     *
     * @param array<string, float> $flags
     */
    public function withFlags(array $flags): self
    {
        $distributions = $this->distributions;
        $distributions['flags'] = $flags + $this->flagProbabilities();

        return new self($distributions);
    }

    /**
     * @return array<string, float>
     */
    public function flagProbabilities(): array
    {
        $flags = $this->distributions['flags'] ?? null;
        if (!\is_array($flags)) {
            return [];
        }

        /** @var array<string, float> $normalized */
        $normalized = [];
        foreach ($flags as $name => $probability) {
            if (\is_numeric($probability)) {
                $normalized[(string) $name] = (float) $probability;
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, float>
     */
    public function transportTimePercentiles(): array
    {
        $percentiles = $this->distributions['transport_time_minutes'] ?? null;
        if (!\is_array($percentiles)) {
            return [];
        }

        /** @var array<string, float> $normalized */
        $normalized = [];
        foreach (['p25', 'p50', 'p75', 'p90'] as $key) {
            if (isset($percentiles[$key]) && \is_numeric($percentiles[$key])) {
                $normalized[$key] = (float) $percentiles[$key];
            }
        }

        return $normalized;
    }
}
