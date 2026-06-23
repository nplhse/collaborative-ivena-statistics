<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerTablePercentHelper;
use App\Statistics\GenericAnalysis\Application\MetricValueFormatter;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use PHPUnit\Framework\TestCase;

final class ExplorerTablePercentHelperTest extends TestCase
{
    private ExplorerTablePercentHelper $helper;

    protected function setUp(): void
    {
        $metricRegistry = new MetricRegistry();
        $this->helper = new ExplorerTablePercentHelper(
            $metricRegistry,
            new MetricValueFormatter($metricRegistry),
        );
    }

    public function testPercentOfTotalReturnsRoundedShare(): void
    {
        self::assertSame(25.0, $this->helper->percentOfTotal(25, 100));
        self::assertSame(33.33, $this->helper->percentOfTotal(1, 3));
    }

    public function testPercentOfTotalReturnsNullForMissingOrZeroDenominator(): void
    {
        self::assertNull($this->helper->percentOfTotal(null, 100));
        self::assertNull($this->helper->percentOfTotal(10, null));
        self::assertNull($this->helper->percentOfTotal(10, 0));
    }

    public function testFormatPercentUsesDashForNull(): void
    {
        self::assertSame('—', $this->helper->formatPercent(null));
    }
}
