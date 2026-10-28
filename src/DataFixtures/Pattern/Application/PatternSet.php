<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Application;

use App\DataFixtures\Generator\Application\Catalog\GeneratorKeys;
use App\DataFixtures\Pattern\Dto\DistributionPattern;
use App\DataFixtures\Pattern\Package\PatternPackage;

/**
 * The distributions a generator run samples from (ADR 022): the code defaults or a pattern package.
 *
 * `source` is what the import marker records: `defaults`, or the checksum of the package. Inside the
 * set departments carry their default label, like the code defaults, so the sampler and the hospital
 * profiles work the same whatever the source; `GeneratorCatalog` binds them to the installed catalog.
 */
final readonly class PatternSet
{
    public const string DEFAULTS = 'defaults';

    /**
     * @param array<string, array<string, mixed>> $segments segment → distributions of a DistributionPattern
     */
    private function __construct(
        public string $source,
        private array $segments,
        private IndicationGroupTable $indicationGroups,
    ) {
    }

    public static function defaults(): self
    {
        $patterns = new DefaultDistributionPatterns();
        $segments = [];
        foreach (DefaultDistributionPatterns::names() as $name) {
            $segments[$name] = $patterns->get($name)->distributions;
        }

        return new self(self::DEFAULTS, $segments, IndicationGroupTable::defaults());
    }

    /**
     * A set from a package that passed validation.
     */
    public static function fromPackage(PatternPackage $package): self
    {
        $groups = [];
        foreach ($package->indicationGroups as $prefix => $group) {
            $groups[$prefix] = ['departments' => self::byLabel($group->departments)] + $group->toArray();
        }

        return new self(
            $package->meta->checksum->value,
            array_map(PatternPackageMapper::distributionsOf(...), $package->segments),
            new IndicationGroupTable($groups, $package->fallbackGroup),
        );
    }

    public function isDefaults(): bool
    {
        return self::DEFAULTS === $this->source;
    }

    /**
     * The pattern of a segment, with the indication groups of this set.
     */
    public function get(string $segment): DistributionPattern
    {
        $distributions = $this->segments[$segment]
            ?? throw new \InvalidArgumentException(sprintf('Unknown distribution pattern "%s". Known: %s.', $segment, implode(', ', array_keys($this->segments))));

        return new DistributionPattern($distributions, $this->indicationGroups);
    }

    /**
     * @return list<string>
     */
    public function segmentNames(): array
    {
        return array_keys($this->segments);
    }

    public function indicationGroups(): IndicationGroupTable
    {
        return $this->indicationGroups;
    }

    /**
     * @param array<array-key, float> $departments generator key → share
     *
     * @return array<string, float> default label → share, same order
     */
    private static function byLabel(array $departments): array
    {
        $byLabel = [];
        foreach ($departments as $key => $share) {
            $byLabel[GeneratorKeys::DEPARTMENTS[(string) $key]['label'] ?? throw new \InvalidArgumentException(sprintf('Unknown department key "%s".', $key))] = $share;
        }

        return $byLabel;
    }
}
