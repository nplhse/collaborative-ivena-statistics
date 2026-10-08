<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

final readonly class ClosureUnitTableRow
{
    public function __construct(
        public string $key,
        public string $hospitalName,
        public string $name,
        public int $eventCount,
        public int $actualMinutes,
        public float $sharePercent,
        public ?int $hospitalId = null,
    ) {
    }
}
