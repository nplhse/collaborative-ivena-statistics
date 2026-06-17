<?php

declare(strict_types=1);

namespace App\Engagement\Application\Dto;

enum MonthlyReminderInsightTrend: string
{
    case Up = 'up';
    case Down = 'down';
    case Neutral = 'neutral';
}

final readonly class MonthlyReminderInsight
{
    public function __construct(
        public string $title,
        public string $body,
        public MonthlyReminderInsightTrend $trend,
        public ?string $linkUrl = null,
    ) {
    }
}
