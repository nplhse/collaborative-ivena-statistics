<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

enum ClosureEventType: string
{
    case Group = 'group';
    case Cluster = 'cluster';
    case Single = 'single';
}
