<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Generator\Run;

use App\DataFixtures\Generator\UI\Console\GenerationRunFormatter;
use PHPUnit\Framework\TestCase;

final class GenerationRunFormatterTest extends TestCase
{
    private const string DEADLOCK = "An exception occurred while executing a query: SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected\nDETAIL:  Process 350925 waits for ShareLock on transaction 4025070; blocked by process 349208.\nCONTEXT:  while deleting tuple (209,174) in relation \"closure_analysis_source\"";

    public function testAnOverviewShowsTheFirstLineOfAnError(): void
    {
        self::assertSame(
            'An exception occurred while executing a query: SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected … (full text with -v)',
            GenerationRunFormatter::error(self::DEADLOCK),
        );
    }

    public function testVerboseOutputShowsTheWholeError(): void
    {
        self::assertSame(self::DEADLOCK, GenerationRunFormatter::error(self::DEADLOCK, full: true));
    }

    public function testShortAndMissingErrors(): void
    {
        self::assertSame('blocked: it has no owner', GenerationRunFormatter::error('blocked: it has no owner'));
        self::assertSame('–', GenerationRunFormatter::error(null));
        self::assertSame('–', GenerationRunFormatter::error('  '));
    }
}
