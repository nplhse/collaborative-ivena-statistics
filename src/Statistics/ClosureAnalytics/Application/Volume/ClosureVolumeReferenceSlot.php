<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

final readonly class ClosureVolumeReferenceSlot
{
    public function __construct(
        public \DateTimeImmutable $hourStart,
        public int $weekday,
        public int $hour,
        public int $dayTimeBucket,
        public int $count,
    ) {
    }
}
