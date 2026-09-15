<?php

declare(strict_types=1);

namespace App\Statistics\GeographicMap\Application\DTO;

final readonly class GeographicHospitalPinSet
{
    /**
     * @param list<GeographicHospitalPin> $pins
     */
    public function __construct(
        public array $pins,
        public int $omittedInsideCount = 0,
        public int $omittedOutsideCount = 0,
    ) {
    }
}
