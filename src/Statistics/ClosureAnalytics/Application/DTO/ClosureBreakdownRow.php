<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

final readonly class ClosureBreakdownRow
{
    public function __construct(
        public string $key,
        public string $name,
        public int $closureCount,
        public int $summedMinutes,
        public int $actualMinutes,
        public int $observedMinutes,
        public ?string $hospitalName = null,
        public ?int $eventCount = null,
        public ?int $hospitalId = null,
    ) {
    }
}
