<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Dto;

use App\DataFixtures\Pattern\Application\IndicationGroupTable;

final readonly class DistributionPattern
{
    /**
     * @param array<string, mixed>      $distributions
     * @param IndicationGroupTable|null $indicationGroups what follows from the indication; null for the code defaults
     */
    public function __construct(
        public array $distributions,
        private ?IndicationGroupTable $indicationGroups = null,
    ) {
    }

    public function indicationGroups(): IndicationGroupTable
    {
        return $this->indicationGroups ?? IndicationGroupTable::defaults();
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
     * A copy with one distribution replaced, for example the indication mix of a scenario.
     *
     * @param array<int|string, float> $distribution labels, e.g. PZC codes (PHP keys most of them as int)
     */
    public function withDistribution(string $key, array $distribution): self
    {
        $distributions = $this->distributions;
        $distributions[$key] = $distribution;

        return new self($distributions, $this->indicationGroups);
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

        return new self($distributions, $this->indicationGroups);
    }

    /**
     * A copy for a hospital that does not offer the given departments.
     *
     * @param list<string> $departments
     */
    public function withoutDepartments(array $departments): self
    {
        $distributions = $this->distributions;
        $distributions['departments_not_offered'] = $departments;

        return new self($distributions, $this->indicationGroups);
    }

    /**
     * @return list<string>
     */
    public function departmentsNotOffered(): array
    {
        $departments = $this->distributions['departments_not_offered'] ?? null;
        if (!\is_array($departments)) {
            return [];
        }

        return array_values(array_map(strval(...), array_filter($departments, \is_string(...))));
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
