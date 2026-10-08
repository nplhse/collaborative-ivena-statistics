<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

enum ClosureEventType: string
{
    case Group = 'source_group';
    case Cluster = 'cluster';
    case Single = 'single';

    /** Legend / swatch suffix for same-day context segments on the event detail timeline. */
    public function timelineLegendKind(): string
    {
        return match ($this) {
            self::Group => 'group',
            self::Cluster => 'cluster',
            self::Single => 'single',
        };
    }
}
