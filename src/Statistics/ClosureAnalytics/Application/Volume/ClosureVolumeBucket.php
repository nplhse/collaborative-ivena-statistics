<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

final readonly class ClosureVolumeBucket
{
    public function __construct(
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
        public int $weekday,
        public int $hour,
        public int $dayTimeBucket,
        public int $seconds,
    ) {
    }
}
