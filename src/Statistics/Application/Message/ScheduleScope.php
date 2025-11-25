<?php

declare(strict_types=1);

namespace App\Statistics\Application\Message;

final class ScheduleScope
{
    public function __construct(
        public int $importId,
    ) {
    }
}
