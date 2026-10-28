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

    public const string CLOSURES = 'closures';

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

    public static function closures(int $seed, string $hospital, string $month): self
    {
        return new self(self::CLOSURES, $seed, self::DEFAULT_SOURCE, $hospital, $month);
    }

    public static function fromPath(string $path): ?self
    {
        if (1 !== preg_match(self::PATTERN, $path, $match)) {
            return null;
        }

        return new self($match['type'], (int) $match['seed'], $match['source'], $match['hospital'], $match['month']);
    }

    /**
     * Path of a scenario import: `synthetic/scenario/icy-road/seed-740/<hospital public id>/2026-01-14.csv`.
     * The suffix tells apart a second import of the same scenario, for example its closures.
     */
    public static function scenarioPath(string $scenario, int $seed, string $hospital, string $startDay, string $suffix = ''): string
    {
        return sprintf('synthetic/scenario/%s/seed-%d/%s/%s%s.csv', $scenario, $seed, $hospital, $startDay, $suffix);
    }

    /**
     * SQL LIKE pattern for every import of a scenario for a hospital and start day, whatever seed.
     */
    public static function likeScenario(string $scenario, string $hospital, string $startDay): string
    {
        return sprintf('synthetic/scenario/%s/%%/%s/%s%%', $scenario, $hospital, $startDay);
    }

    /**
     * SQL LIKE pattern for every synthetic import of this type, hospital and month, whatever seed and source.
     */
    /**
     * LIKE pattern for every hospital's imports of a scenario with a seed and start day.
     */
    public static function likeScenarioAnyHospital(string $scenario, int $seed, string $startDay): string
    {
        return sprintf('synthetic/scenario/%s/seed-%d/%%/%s%%', $scenario, $seed, $startDay);
    }

    public static function likeAnySeed(string $type, string $hospital, string $month): string
    {
        return sprintf('synthetic/%s/%%/%s/%s.csv', $type, $hospital, $month);
    }

    public function path(): string
    {
        return sprintf('synthetic/%s/seed-%d/%s/%s/%s.csv', $this->type, $this->seed, $this->source, $this->hospital, $this->month);
    }
}
