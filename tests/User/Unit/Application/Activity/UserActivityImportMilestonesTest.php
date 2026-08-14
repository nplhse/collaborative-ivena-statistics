<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Application\Activity;

use App\User\Application\Activity\UserActivityImportMilestones;
use PHPUnit\Framework\TestCase;

final class UserActivityImportMilestonesTest extends TestCase
{
    public function testKnownRanksAreMilestones(): void
    {
        self::assertTrue(UserActivityImportMilestones::isMilestone(1));
        self::assertTrue(UserActivityImportMilestones::isMilestone(10));
        self::assertFalse(UserActivityImportMilestones::isMilestone(11));
        self::assertFalse(UserActivityImportMilestones::isMilestone(49));
        self::assertTrue(UserActivityImportMilestones::isMilestone(50));
    }
}
