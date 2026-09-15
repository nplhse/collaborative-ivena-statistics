<?php

declare(strict_types=1);

namespace App\Statistics\GeographicMap\Application\DTO;

final readonly class GeographicHospitalPin
{
    public function __construct(
        public int $hospitalId,
        public string $name,
        public float $lat,
        public float $lng,
        public int $caseCount,
        public float $sharePercent,
        public ?int $tierCode,
        public ?int $locationCode,
        public bool $suppressed,
        public bool $insideSelectedArea = true,
    ) {
    }

    /**
     * @return array{
     *     hospitalId: int,
     *     name: string,
     *     lat: float,
     *     lng: float,
     *     caseCount: int,
     *     sharePercent: float,
     *     tierCode: ?int,
     *     locationCode: ?int,
     *     suppressed: bool,
     *     insideSelectedArea: bool
     * }
     */
    public function toMapPayload(): array
    {
        return [
            'hospitalId' => $this->hospitalId,
            'name' => $this->name,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'caseCount' => $this->caseCount,
            'sharePercent' => $this->sharePercent,
            'tierCode' => $this->tierCode,
            'locationCode' => $this->locationCode,
            'suppressed' => $this->suppressed,
            'insideSelectedArea' => $this->insideSelectedArea,
        ];
    }
}
