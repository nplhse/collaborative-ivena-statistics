<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Application\Activity;

use App\User\Application\Activity\UserActivityImportMilestones;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserActivityImportMilestones::class)]
final class UserActivityImportMilestonesTest extends TestCase
{
    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function counts(): iterable
    {
        yield 'first import' => [1, true];
        yield 'tenth import' => [10, true];
        yield 'just after a milestone' => [11, false];
        yield 'just before a milestone' => [49, false];
        yield 'fiftieth import' => [50, true];
    }

    #[DataProvider('counts')]
    public function testKnownRanksAreMilestones(int $successfulCount, bool $milestone): void
    {
        $isMilestone = UserActivityImportMilestones::isMilestone($successfulCount);

        self::assertSame($milestone, $isMilestone);
    }
}
