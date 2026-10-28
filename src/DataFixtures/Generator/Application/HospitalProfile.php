<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application;

use App\DataFixtures\Pattern\Dto\DistributionPattern;

/**
 * How one synthetic hospital behaves (ADR 022).
 *
 * Whether it has a catheterization laboratory is fixed when the data is generated: without one it
 * gets no cath lab cases. Resuscitation room cases happen everywhere, but rarely in small hospitals.
 */
final readonly class HospitalProfile
{
    /**
     * @param string $segment    default pattern of the hospital (see DefaultDistributionPatterns::names())
     * @param float  $resusShare share of allocations that need the resuscitation room
     */
    public function __construct(
        public string $segment,
        public bool $hasCathlab,
        public float $resusShare,
    ) {
    }

    public function applyTo(DistributionPattern $pattern): DistributionPattern
    {
        $flags = ['requires_resus' => $this->resusShare];
        if (!$this->hasCathlab) {
            $flags['requires_cathlab'] = 0.0;
        }

        return $pattern->withFlags($flags);
    }
}
