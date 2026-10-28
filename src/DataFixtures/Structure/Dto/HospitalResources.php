<?php

declare(strict_types=1);

namespace App\DataFixtures\Structure\Dto;

/**
 * The resources of one hospital, independent of its care tier (ADR 022).
 *
 * They are part of a configuration and are never drawn one by one, so a hospital always gets a
 * coherent combination (see StructureRules).
 */
final readonly class HospitalResources
{
    public function __construct(
        public bool $cathlab = false,
        public bool $neurosurgery = false,
        public StrokeUnitLevel $strokeUnit = StrokeUnitLevel::NONE,
        public TraumaLevel $traumaLevel = TraumaLevel::BASIC,
        public ObstetricsLevel $obstetrics = ObstetricsLevel::NONE,
        public bool $paediatrics = false,
    ) {
    }

    public function provides(Capability $capability): bool
    {
        return match ($capability) {
            Capability::CATHLAB => $this->cathlab,
            Capability::NEUROSURGERY => $this->neurosurgery,
            Capability::STROKE_UNIT => StrokeUnitLevel::NONE !== $this->strokeUnit,
            Capability::PAEDIATRICS => $this->paediatrics,
            Capability::TRAUMA_REGIONAL => $this->traumaLevel->rank() >= TraumaLevel::REGIONAL->rank(),
            Capability::TRAUMA_SUPRA => TraumaLevel::SUPRA === $this->traumaLevel,
            Capability::OBSTETRICS_BASIC => $this->obstetrics->rank() >= ObstetricsLevel::BASIC->rank(),
            Capability::OBSTETRICS_PERINATAL => ObstetricsLevel::PERINATAL === $this->obstetrics,
        };
    }

    /**
     * A copy with the given resources replaced; null keeps a resource.
     */
    public function with(
        ?bool $cathlab = null,
        ?bool $neurosurgery = null,
        ?StrokeUnitLevel $strokeUnit = null,
        ?TraumaLevel $traumaLevel = null,
        ?ObstetricsLevel $obstetrics = null,
        ?bool $paediatrics = null,
    ): self {
        return new self(
            cathlab: $cathlab ?? $this->cathlab,
            neurosurgery: $neurosurgery ?? $this->neurosurgery,
            strokeUnit: $strokeUnit ?? $this->strokeUnit,
            traumaLevel: $traumaLevel ?? $this->traumaLevel,
            obstetrics: $obstetrics ?? $this->obstetrics,
            paediatrics: $paediatrics ?? $this->paediatrics,
        );
    }
}
