<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application;

use App\DataFixtures\FixtureVolume;
use App\DataFixtures\Pattern\Dto\DistributionPattern;
use App\DataFixtures\Structure\Dto\HospitalResources;
use App\DataFixtures\Structure\Dto\MixTilt;

/**
 * How one synthetic hospital behaves (ADR 022).
 *
 * The configuration and its resources are fixed when the data is generated: without a cath lab a
 * hospital gets no cath lab cases, and an indication that needs a resource the hospital lacks (a
 * STEMI without cath lab, a preterm birth without a perinatal centre) goes elsewhere. Resuscitation
 * room cases happen everywhere, but rarely without a trauma centre. A department the hospital does
 * not offer gets no allocations and no closures. Volume and case mix differ from hospital to
 * hospital: the volume factor scales the preset rate of the size, the tilt the mix of the segment.
 */
final readonly class HospitalProfile
{
    private const float DAYS_PER_YEAR = 365.0;

    public bool $hasCathlab;

    /**
     * @param string       $segment            default pattern of the hospital (see DefaultDistributionPatterns::names())
     * @param float        $resusShare         share of allocations that need the resuscitation room
     * @param list<string> $missingDepartments departments the hospital does not offer (default labels)
     * @param string       $configuration      key of the hospital configuration it was drawn from
     * @param list<string> $blockedIndications PZC code prefixes the hospital lacks the capability for
     * @param string       $size               size the volume anchor is taken from (Small, Medium, Large)
     * @param float        $volumeFactor       factor on the preset rate of the size (beds and hospital factor)
     * @param int|null     $annualVolume       explicit allocations per year; replaces preset rate and factor
     * @param float        $closureShare       share of department-hours that are closed (merged per department)
     */
    public function __construct(
        public string $segment,
        public HospitalResources $resources,
        public float $resusShare,
        public array $missingDepartments = [],
        public string $configuration = 'custom',
        public array $blockedIndications = [],
        public string $size = 'Medium',
        public float $volumeFactor = 1.0,
        public ?int $annualVolume = null,
        public float $closureShare = 0.0,
        public MixTilt $tilt = new MixTilt(),
    ) {
        $this->hasCathlab = $this->resources->cathlab;
    }

    public function offers(string $department): bool
    {
        return !\in_array($department, $this->missingDepartments, true);
    }

    /**
     * Expected allocations per day before weekday, month and trend: the explicit annual volume, or
     * the preset rate of the size times the volume factor.
     */
    public function dailyRate(FixtureVolume $volume): float
    {
        if (null !== $this->annualVolume) {
            return (float) $this->annualVolume / self::DAYS_PER_YEAR;
        }

        return $volume->dailyRate($this->size) * $this->volumeFactor;
    }

    public function applyTo(DistributionPattern $pattern): DistributionPattern
    {
        $flags = ['requires_resus' => $this->resusShare];
        if (!$this->hasCathlab) {
            $flags['requires_cathlab'] = 0.0;
        }

        return $pattern->withFlags($flags)
            ->withoutDepartments($this->missingDepartments)
            ->withoutIndications($this->blockedIndications)
            ->withTilt($this->tilt->toArray());
    }
}
