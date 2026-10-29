<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Demo;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Faker\Provider\Hospital as HospitalFaker;

/**
 * Master data of a synthetic hospital that the demo wizard creates (#791). The size follows the beds, with the
 * bed bands of the synthetic hospitals (HospitalFaker::BED_BANDS).
 */
final readonly class NewDemoHospital
{
    public HospitalSize $size;

    /**
     * @param float|null $latitude  coordinates without a prepared location; null leaves the hospital without a pin
     * @param float|null $longitude see $latitude
     *
     * @throws \InvalidArgumentException when a figure is out of range
     */
    public function __construct(
        public string $name,
        public HospitalTier $tier,
        public int $beds,
        public int $dispatchAreaId,
        public HospitalLocation $location = HospitalLocation::MIXED,
        public ?float $latitude = null,
        public ?float $longitude = null,
    ) {
        if ('' === trim($name)) {
            throw new \InvalidArgumentException('The hospital needs a name.');
        }
        if ($beds <= 0) {
            throw new \InvalidArgumentException('Beds must be more than 0.');
        }
        if ((null === $latitude) !== (null === $longitude)) {
            throw new \InvalidArgumentException('Give both latitude and longitude, or neither.');
        }
        if (null !== $latitude && ($latitude < -90.0 || $latitude > 90.0 || $longitude < -180.0 || $longitude > 180.0)) {
            throw new \InvalidArgumentException('The coordinates are out of range.');
        }
        $this->size = self::sizeForBeds($beds);
    }

    public static function sizeForBeds(int $beds): HospitalSize
    {
        return HospitalSize::from(HospitalFaker::sizeForBeds($beds));
    }
}
