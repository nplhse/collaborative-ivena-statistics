<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Dto;

final readonly class DistributionPattern
{
    public const string SCHEMA = 'distribution-pattern/v1';

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $distributions
     */
    public function __construct(
        public string $name,
        public int $version,
        public string $schema,
        public PatternSegment $segment,
        public array $meta,
        public array $distributions,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $name, array $data): self
    {
        if (!isset($data['segment']) || !\is_array($data['segment'])) {
            throw new \InvalidArgumentException(sprintf('Pattern "%s" is missing segment.', $name));
        }
        if (!isset($data['distributions']) || !\is_array($data['distributions'])) {
            throw new \InvalidArgumentException(sprintf('Pattern "%s" is missing distributions.', $name));
        }

        /** @var array{hospital_tier: string, hospital_location: string} $segment */
        $segment = $data['segment'];
        $meta = $data['meta'] ?? [];

        return new self(
            name: $name,
            version: (int) ($data['version'] ?? 0),
            schema: (string) ($data['schema'] ?? ''),
            segment: PatternSegment::fromArray($segment),
            meta: \is_array($meta) ? $meta : [],
            distributions: $data['distributions'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'schema' => $this->schema,
            'segment' => $this->segment->toArray(),
            'meta' => $this->meta,
            'distributions' => $this->distributions,
        ];
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
