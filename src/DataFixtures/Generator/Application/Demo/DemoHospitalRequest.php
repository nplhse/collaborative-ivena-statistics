<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Demo;

use App\DataFixtures\Generator\Application\Dataset\ExistingData;

/**
 * What the presenter chose in the demo wizard (#791): a new hospital or an existing one, and the simulation.
 */
final readonly class DemoHospitalRequest
{
    public const array MONTHS = [6, 12, 24];

    /**
     * @param int|null    $existingHospitalId hospital of the demo database; null with $newHospital
     * @param float       $closureShare       share of department-hours that are closed, 0 to below 1
     * @param string|null $preparedLocation   slug of a prepared location to apply after the hospital exists
     *
     * @throws \InvalidArgumentException when the choice is incomplete or out of range
     */
    public function __construct(
        public ?NewDemoHospital $newHospital,
        public ?int $existingHospitalId,
        public int $annualVolume,
        public int $months,
        public float $closureShare,
        public ?string $preparedLocation = null,
        public ExistingData $existing = ExistingData::Refuse,
    ) {
        if ($newHospital instanceof NewDemoHospital === (null !== $existingHospitalId)) {
            throw new \InvalidArgumentException('Choose either a new hospital or an existing one.');
        }
        if ($annualVolume <= 0) {
            throw new \InvalidArgumentException('The annual allocations must be more than 0.');
        }
        if (!\in_array($months, self::MONTHS, true)) {
            throw new \InvalidArgumentException(\sprintf('The simulation period is %s months.', implode(', ', self::MONTHS)));
        }
        if ($closureShare < 0.0 || $closureShare >= 1.0) {
            throw new \InvalidArgumentException('The closure share lies from 0 to below 1 (0.05 = 5 % of department-hours closed).');
        }
        if (null !== $preparedLocation && null !== $newHospital?->latitude) {
            throw new \InvalidArgumentException('Give a prepared location or coordinates, not both.');
        }
    }

    public function isNewHospital(): bool
    {
        return $this->newHospital instanceof NewDemoHospital;
    }
}
