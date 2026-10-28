<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application;

use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\DataFixtures\Structure\Dto\ObstetricsLevel;
use App\DataFixtures\Structure\Dto\StrokeUnitLevel;
use App\DataFixtures\Structure\Dto\TraumaLevel;

/**
 * An explicit choice of hospital structure for targeted scenarios and the generation service of
 * #877 (ADR 022): a configuration of the structural patterns, resources changed on top of the drawn or
 * chosen one, and the figures a wizard asks for.
 *
 * Null keeps what the hospital would get without a selection: tier, size and beds of the catalog
 * hospital, the drawn configuration and volume, the closure share of its size. An explicit annual
 * volume replaces the volume model (preset rate, beds and hospital factor), it is not multiplied
 * with it. The result must stay coherent (StructureRules), otherwise the profile is refused.
 */
final readonly class ProfileSelection
{
    /**
     * @param int|null   $annualVolume expected allocations per year, at the trend reference day
     * @param float|null $closureShare share of department-hours that are closed, from 0 to below 1
     */
    public function __construct(
        public ?string $configuration = null,
        public ?bool $cathlab = null,
        public ?bool $neurosurgery = null,
        public ?StrokeUnitLevel $strokeUnit = null,
        public ?TraumaLevel $traumaLevel = null,
        public ?ObstetricsLevel $obstetrics = null,
        public ?bool $paediatrics = null,
        public ?HospitalTier $tier = null,
        public ?HospitalSize $size = null,
        public ?int $beds = null,
        public ?int $annualVolume = null,
        public ?float $closureShare = null,
    ) {
    }

    /**
     * A copy that uses the given closure share unless this selection sets one (a preset share is a
     * default, an explicit selection wins).
     */
    public function withDefaultClosureShare(?float $share): self
    {
        if (null === $share || null !== $this->closureShare) {
            return $this;
        }

        return new self(
            $this->configuration, $this->cathlab, $this->neurosurgery, $this->strokeUnit, $this->traumaLevel, $this->obstetrics,
            $this->paediatrics, $this->tier, $this->size, $this->beds, $this->annualVolume, $share,
        );
    }

    /**
     * @return list<string> what is out of range, empty when the figures are usable
     */
    public function violations(): array
    {
        $violations = [];
        if (null !== $this->beds && $this->beds <= 0) {
            $violations[] = 'beds must be more than 0';
        }
        if (null !== $this->annualVolume && $this->annualVolume <= 0) {
            $violations[] = 'the annual volume must be more than 0';
        }
        if (null !== $this->closureShare && ($this->closureShare < 0.0 || $this->closureShare >= 1.0)) {
            $violations[] = 'the closure share must lie from 0 to below 1';
        }

        return $violations;
    }
}
