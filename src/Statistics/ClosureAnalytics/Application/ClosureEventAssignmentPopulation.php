<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application;

enum ClosureEventAssignmentPopulation: string
{
    case Speciality = 'speciality';
    case Closed = 'closed';

    public static function fromRequest(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Closed;
    }
}
