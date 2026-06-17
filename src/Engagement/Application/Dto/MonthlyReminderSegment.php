<?php

declare(strict_types=1);

namespace App\Engagement\Application\Dto;

final readonly class MonthlyReminderSegment
{
    public function __construct(
        public string $label,
        public float $percent,
        public string $color,
    ) {
    }
}
