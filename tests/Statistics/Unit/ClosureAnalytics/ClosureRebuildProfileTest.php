<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\ClosureAnalytics\Infrastructure\Monitoring\ClosureRebuildProfile;
use PHPUnit\Framework\TestCase;

final class ClosureRebuildProfileTest extends TestCase
{
    public function testStepsAddUpAndAreSortedBySeconds(): void
    {
        $profile = new ClosureRebuildProfile();

        self::assertSame(42, $profile->time('plan', static fn (): int => 42));
        $profile->record('insert', 2.0, 1);
        $profile->record('insert', 1.0, 1);
        $profile->countQueries('hourly_counts', 4);

        $steps = $profile->steps();
        self::assertSame(['insert', 'plan', 'hourly_counts'], array_keys($steps));
        self::assertEqualsWithDelta(3.0, $steps['insert']['seconds'], 0.0001);
        self::assertSame(2, $steps['insert']['queries']);
        self::assertSame(6, $profile->queries());

        $profile->reset();
        self::assertSame([], $profile->steps());
        self::assertSame(0, $profile->queries());
    }

    public function testFailingStepIsStillTimed(): void
    {
        $profile = new ClosureRebuildProfile();

        try {
            $profile->time('publish', static function (): never {
                throw new \RuntimeException('failed');
            }, 3);
        } catch (\RuntimeException) {
        }

        self::assertSame(3, $profile->steps()['publish']['queries']);
    }
}
