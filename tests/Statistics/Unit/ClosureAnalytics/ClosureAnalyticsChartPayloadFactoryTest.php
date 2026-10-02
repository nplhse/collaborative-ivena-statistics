<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\ClosureAnalytics\Application\DTO\ClosureHeatmapCell;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureTimeBucket;
use App\Statistics\ClosureAnalytics\UI\Http\Controller\ClosureAnalyticsChartPayloadFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Translator;

final class ClosureAnalyticsChartPayloadFactoryTest extends TestCase
{
    public function testDevelopmentChartUsesWholeHours(): void
    {
        $payload = $this->factory()->dashboard([
            new ClosureTimeBucket('2026-09', 0, 29, 29, 0, 1),
            new ClosureTimeBucket('2026-10', 0, 90, 90, 0, 1),
        ], [
            new ClosureHeatmapCell(1, 0, 0, 30),
        ]);

        self::assertSame(['2026-09', '2026-10'], $payload['timeSeries']['labels']);
        self::assertSame([0, 2], $payload['timeSeries']['closedHours']);
    }

    private function factory(): ClosureAnalyticsChartPayloadFactory
    {
        return new ClosureAnalyticsChartPayloadFactory(new Translator('en'));
    }
}
