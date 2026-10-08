<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeSeriesScope;
use PHPUnit\Framework\TestCase;

final class ClosureVolumeSeriesScopeTest extends TestCase
{
    public function testSqlValuesMatchProjectionScope(): void
    {
        self::assertSame('department', ClosureVolumeSeriesScope::Department->sqlValue());
        self::assertSame('speciality', ClosureVolumeSeriesScope::Speciality->sqlValue());
    }
}
