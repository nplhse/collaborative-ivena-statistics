<?php

declare(strict_types=1);

namespace App\Statistics\DataQuality\Dto;

final readonly class DataQualityHospitalSnapshot
{
    public function __construct(
        public int $id,
        public string $size,
        public ?string $careLevel,
        public string $urbanity,
        public string $landkreis,
    ) {
    }
}
