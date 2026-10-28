<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application;

use App\DataFixtures\Pattern\Dto\DistributionPattern;
use App\DataFixtures\Structure\Dto\HospitalResources;

/**
 * How one synthetic hospital behaves (ADR 022).
 *
 * The configuration and its resources are fixed when the data is generated: without a cath lab a
 * hospital gets no cath lab cases, and an indication that needs a resource the hospital lacks (a
 * STEMI without cath lab, a preterm birth without a perinatal centre) goes elsewhere. Resuscitation
 * room cases happen everywhere, but rarely without a trauma centre. A department the hospital does
 * not offer gets no allocations and no closures.
 */
final readonly class HospitalProfile
{
    public bool $hasCathlab;

    /**
     * @param string       $segment               default pattern of the hospital (see DefaultDistributionPatterns::names())
     * @param float        $resusShare            share of allocations that need the resuscitation room
     * @param list<string> $missingDepartments    departments the hospital does not offer
     * @param float        $closureEventsPerMonth average closure events per 30 days
     * @param string       $configuration         key of the hospital configuration it was drawn from
     * @param list<string> $blockedIndications    PZC code prefixes the hospital lacks the capability for
     */
    public function __construct(
        public string $segment,
        public HospitalResources $resources,
        public float $resusShare,
        public array $missingDepartments = [],
        public float $closureEventsPerMonth = 5.0,
        public string $configuration = 'custom',
        public array $blockedIndications = [],
    ) {
        $this->hasCathlab = $this->resources->cathlab;
    }

    public function offers(string $department): bool
    {
        return !\in_array($department, $this->missingDepartments, true);
    }

    public function applyTo(DistributionPattern $pattern): DistributionPattern
    {
        $flags = ['requires_resus' => $this->resusShare];
        if (!$this->hasCathlab) {
            $flags['requires_cathlab'] = 0.0;
        }

        return $pattern->withFlags($flags)
            ->withoutDepartments($this->missingDepartments)
            ->withoutIndications($this->blockedIndications);
    }
}
