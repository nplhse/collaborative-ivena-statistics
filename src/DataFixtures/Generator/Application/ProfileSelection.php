<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application;

use App\DataFixtures\Structure\Dto\ObstetricsLevel;
use App\DataFixtures\Structure\Dto\StrokeUnitLevel;
use App\DataFixtures\Structure\Dto\TraumaLevel;

/**
 * An explicit choice of hospital structure for targeted scenarios (ADR 022): a configuration of the
 * structural patterns, a resource changed on top of the drawn or chosen one, or both.
 *
 * Null keeps what the hospital would get without a selection. The result must stay coherent
 * (StructureRules), otherwise the profile is refused.
 */
final readonly class ProfileSelection
{
    public function __construct(
        public ?string $configuration = null,
        public ?bool $cathlab = null,
        public ?bool $neurosurgery = null,
        public ?StrokeUnitLevel $strokeUnit = null,
        public ?TraumaLevel $traumaLevel = null,
        public ?ObstetricsLevel $obstetrics = null,
        public ?bool $paediatrics = null,
    ) {
    }
}
