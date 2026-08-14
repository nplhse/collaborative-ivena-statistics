<?php

declare(strict_types=1);

namespace App\User\Application\Activity;

final class UserActivityImportMilestones
{
    /** @var list<int> */
    public const array RANKS = [1, 10, 25, 50, 100, 200, 500, 1000];

    public static function isMilestone(int $successfulCount): bool
    {
        return \in_array($successfulCount, self::RANKS, true);
    }
}
