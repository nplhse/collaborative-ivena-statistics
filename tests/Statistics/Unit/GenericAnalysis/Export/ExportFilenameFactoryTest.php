<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis\Export;

use App\Statistics\GenericAnalysis\Application\Export\ExportFilenameFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ExportFilenameFactoryTest extends TestCase
{
    public function testCreatesSafeSluggedFilename(): void
    {
        $filename = new ExportFilenameFactory(new MockClock('2026-05-15 10:00:00'))->create('Allocations over time!', 'csv');

        self::assertSame('allocations-over-time-2026-05-15.csv', $filename);
    }

    public function testFallsBackWhenTitleIsEmpty(): void
    {
        $filename = new ExportFilenameFactory(new MockClock('2026-05-15 10:00:00'))->create('!!!', 'csv');

        self::assertStringStartsWith('analysis-export-', $filename);
        self::assertStringEndsWith('.csv', $filename);
    }
}
