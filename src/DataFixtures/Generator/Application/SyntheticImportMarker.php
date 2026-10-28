<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application;

/**
 * The file path of a synthetic import (ADR 022). No file exists at this path.
 *
 * It records seed, pattern source, hospital and month, so the generator can tell which months
 * already exist: `synthetic/allocations/seed-740/defaults/<hospital public id>/2026-09.csv`.
 */
final readonly class SyntheticImportMarker
{
    public const string ALLOCATIONS = 'allocations';

    public const string DEFAULT_SOURCE = 'defaults';

    private const string PATTERN = '#^synthetic/(?<type>[a-z]+)/seed-(?<seed>\d+)/(?<source>[a-z0-9-]+)/(?<hospital>[0-9a-f-]{36})/(?<month>\d{4}-\d{2})\.csv$#';

    public function __construct(
        public string $type,
        public int $seed,
        public string $source,
        public string $hospital,
        public string $month,
    ) {
    }

    public static function allocations(int $seed, string $hospital, string $month): self
    {
        return new self(self::ALLOCATIONS, $seed, self::DEFAULT_SOURCE, $hospital, $month);
    }

    public static function fromPath(string $path): ?self
    {
        if (1 !== preg_match(self::PATTERN, $path, $match)) {
            return null;
        }

        return new self($match['type'], (int) $match['seed'], $match['source'], $match['hospital'], $match['month']);
    }

    /**
     * SQL LIKE pattern for every synthetic import of this type, hospital and month, whatever seed and source.
     */
    public static function likeAnySeed(string $type, string $hospital, string $month): string
    {
        return sprintf('synthetic/%s/%%/%s/%s.csv', $type, $hospital, $month);
    }

    public function path(): string
    {
        return sprintf('synthetic/%s/seed-%d/%s/%s/%s.csv', $this->type, $this->seed, $this->source, $this->hospital, $this->month);
    }
}
