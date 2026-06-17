<?php

declare(strict_types=1);

namespace App\Statistics\Application\IndicationDashboard;

enum IndicationSubjectType: string
{
    case Single = 'single';
    case Group = 'group';
}
