<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

final readonly class ClosureTimeBucket
{
    public function __construct(
        public string $key,
        public int $observedMinutes,
        public int $closedMinutes,
        public int $singleMinutes,
        public int $multipleMinutes,
        public int $closureCount,
    ) {
    }
}
