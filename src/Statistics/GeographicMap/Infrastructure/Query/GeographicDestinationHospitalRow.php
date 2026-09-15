<?php

declare(strict_types=1);

namespace App\Statistics\GeographicMap\Infrastructure\Query;

final readonly class GeographicDestinationHospitalRow
{
    public function __construct(
        public int $hospitalId,
        public string $name,
        public ?float $lat,
        public ?float $lng,
        public int $caseCount,
        public ?int $tierCode,
        public ?int $locationCode,
        public ?int $hospitalDispatchAreaId = null,
    ) {
    }
}
