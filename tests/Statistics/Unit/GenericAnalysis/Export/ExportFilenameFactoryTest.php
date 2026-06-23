<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis\Export;

use App\Statistics\GenericAnalysis\Application\Export\ExportFilenameFactory;
use PHPUnit\Framework\TestCase;

final class ExportFilenameFactoryTest extends TestCase
{
    public function testCreatesSafeSluggedFilename(): void
    {
        $filename = new ExportFilenameFactory()->create('Allocations over time!', 'csv');

        self::assertMatchesRegularExpression('/^allocations-over-time-\d{4}-\d{2}-\d{2}\.csv$/', $filename);
    }

    public function testFallsBackWhenTitleIsEmpty(): void
    {
        $filename = new ExportFilenameFactory()->create('!!!', 'csv');

        self::assertStringStartsWith('analysis-export-', $filename);
        self::assertStringEndsWith('.csv', $filename);
    }
}
