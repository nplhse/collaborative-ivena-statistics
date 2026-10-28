<?php

declare(strict_types=1);

namespace App\DataFixtures\Structure\Dto;

/**
 * The structural patterns the hospital profiles are drawn from: code defaults or, later, a package.
 *
 * It holds no hospital names, ids or locations; a profile combines it with the tier, size and
 * location of a catalog hospital.
 */
final readonly class StructurePatternSet
{
    /** @var array<string, HospitalConfiguration> */
    public array $configurations;

    /**
     * @param list<HospitalConfiguration>   $configurations in draw order
     * @param array<int|string, Capability> $gates          PZC code prefix (keyed as int by PHP) → capability a hospital needs for it
     * @param array<string, float>          $resusShares    trauma level → share of allocations that need the resuscitation room
     */
    public function __construct(
        array $configurations,
        public array $gates,
        public array $resusShares,
    ) {
        $keyed = [];
        foreach ($configurations as $configuration) {
            $keyed[$configuration->key] = $configuration;
        }
        $this->configurations = $keyed;
    }

    /**
     * @throws \DomainException for an unknown configuration
     */
    public function configuration(string $key): HospitalConfiguration
    {
        return $this->configurations[$key] ?? throw new \DomainException(sprintf('Unknown hospital configuration "%s". Supported: %s.', $key, implode(', ', array_keys($this->configurations))));
    }

    /**
     * @return array<string, float> configuration key → weight, only those drawn for this tier and size
     */
    public function weightsFor(string $tier, string $size): array
    {
        $weights = [];
        foreach ($this->configurations as $key => $configuration) {
            $weight = $configuration->weight($tier, $size);
            if ($weight > 0.0) {
                $weights[$key] = $weight;
            }
        }

        return $weights;
    }

    /**
     * The PZC code prefixes whose capability the resources do not provide.
     *
     * @return list<string>
     */
    public function blockedIndications(HospitalResources $resources): array
    {
        $blocked = [];
        foreach ($this->gates as $prefix => $capability) {
            if (!$resources->provides($capability)) {
                $blocked[] = (string) $prefix;
            }
        }

        return $blocked;
    }
}
