<?php

declare(strict_types=1);

namespace App\DataFixtures\Structure\Dto;

/**
 * A coherent bundle of resources and departments: the unit a hospital structure is drawn as.
 *
 * The weight by tier and size is an influence, not a rule: an uncommon configuration has a small
 * weight, and a configuration with weight 0 for a tier and size is still supported, so a targeted
 * scenario can select it explicitly (ProfileSelection).
 */
final readonly class HospitalConfiguration
{
    /**
     * @param array<string, array<string, float>> $weights     tier → size → relative weight
     * @param list<string>                        $departments department keys the configuration always offers, besides the core and those its resources imply
     * @param array<string, array<int, float>>    $fieldCounts speciality key → number of further departments of that field → share
     */
    public function __construct(
        public string $key,
        public array $weights,
        public HospitalResources $resources,
        public array $departments = [],
        public array $fieldCounts = [],
    ) {
    }

    public function weight(string $tier, string $size): float
    {
        return $this->weights[$tier][$size] ?? 0.0;
    }

    public function withResources(HospitalResources $resources): self
    {
        return new self($this->key, $this->weights, $resources, $this->departments, $this->fieldCounts);
    }
}
