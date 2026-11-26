<?php

declare(strict_types=1);

namespace App\Tests\Admin\Unit\Audit;

use App\Admin\Application\Audit\AuditRequestStack;
use App\Admin\Application\Audit\AuditRequestStats;
use PHPUnit\Framework\TestCase;

final class AuditRequestStackTest extends TestCase
{
    public function testOnlyBackgroundEntriesOnThePageAreTheRequestItself(): void
    {
        $stack = new AuditRequestStack('r1', [
            AuditTimelineFixture::entry(1, '10:00:00', 'r1', origin: 'messenger'),
            AuditTimelineFixture::entry(2, '10:00:01', 'r1', origin: 'messenger'),
        ], new AuditRequestStats(5, 5, 1, 2));

        self::assertCount(2, $stack->ownEntries());
        self::assertSame([], $stack->backgroundEntries());
        self::assertFalse($stack->isComplete());
    }

    public function testIntentIsTheFirstEntryWithOne(): void
    {
        $stack = new AuditRequestStack('r1', [
            AuditTimelineFixture::entry(1, '10:00:00', 'r1'),
            AuditTimelineFixture::entry(2, '10:00:00', 'r1', intent: 'user.admin.updated'),
            AuditTimelineFixture::entry(3, '10:00:00', 'r1', intent: 'other'),
        ], new AuditRequestStats(3, 3, 1, 1));

        self::assertSame('user.admin.updated', $stack->intent());
        self::assertTrue($stack->isComplete());
    }

    public function testEntityNamesAreTheMostFrequentTypesOnThePage(): void
    {
        $entry = static fn (int $id, string $class): \App\Admin\Application\Audit\AuditTimelineEntry => new \App\Admin\Application\Audit\AuditTimelineEntry(
            $id, new \DateTimeImmutable('2026-11-13 10:00:00'), 'r1', false, null, null, 'cli', 'create', $class, (string) $id, null, null, null, [],
        );
        $stack = new AuditRequestStack('r1', [
            $entry(1, 'App\\A\\State'),
            $entry(2, 'App\\A\\Hospital'),
            $entry(3, 'App\\A\\Hospital'),
            $entry(4, 'App\\A\\DispatchArea'),
            $entry(5, 'App\\A\\Department'),
            $entry(6, 'App\\A\\Department'),
            $entry(7, 'App\\A\\Department'),
        ], new AuditRequestStats(7, 7, 1, 1));

        self::assertSame(['Department', 'Hospital', 'State'], $stack->entityNames());
        self::assertSame(['Department'], $stack->entityNames(1));
    }
}
