<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

enum ClosureEventAssignmentRowContext: string
{
    case EventMemberClosed = 'event_member_closed';
    case EventMemberOpen = 'event_member_open';
    case SpecialityOnly = 'speciality_only';
}
