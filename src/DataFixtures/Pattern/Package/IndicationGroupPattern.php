<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Package;

/**
 * What follows from an indication whose PZC code starts with the group's prefix (ADR 022):
 * department, urgency, age range, female share, resuscitation room factor and flags.
 */
final readonly class IndicationGroupPattern
{
    /**
     * @param array<array-key, float> $departments department key (GeneratorKeys) → share
     * @param array<array-key, float> $urgency     urgency 1–3 → share
     * @param array<array-key, float> $ages        age range ("18-39") → share
     * @param array<array-key, float> $flags       flag → probability
     */
    public function __construct(
        public array $departments,
        public array $urgency,
        public array $ages,
        public float $female,
        public float $resusFactor,
        public array $flags,
    ) {
    }

    /**
     * @param array<string, mixed> $data a group that passed the structure check
     */
    public static function fromArray(array $data): self
    {
        /* @var array{departments: array<array-key, float|int>, urgency: array<array-key, float|int>, ages: array<array-key, float|int>, female: float|int, resus_factor: float|int, flags: array<array-key, float|int>} $data */
        return new self(
            array_map(floatval(...), $data['departments']),
            array_map(floatval(...), $data['urgency']),
            array_map(floatval(...), $data['ages']),
            (float) $data['female'],
            (float) $data['resus_factor'],
            array_map(floatval(...), $data['flags']),
        );
    }

    /**
     * Same keys as a group of IndicationGroups.
     *
     * @return array{departments: array<array-key, float>, urgency: array<array-key, float>, ages: array<array-key, float>, female: float, resus_factor: float, flags: array<array-key, float>}
     */
    public function toArray(): array
    {
        return [
            'departments' => $this->departments,
            'urgency' => $this->urgency,
            'ages' => $this->ages,
            'female' => $this->female,
            'resus_factor' => $this->resusFactor,
            'flags' => $this->flags,
        ];
    }
}
