<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Unit\Engagement;

use App\Analytics\Application\Engagement\EngagementDepthResolver;
use App\Analytics\Domain\UsageEventName;
use PHPUnit\Framework\TestCase;

final class EngagementDepthResolverTest extends TestCase
{
    public function testEventLevels(): void
    {
        $resolver = new EngagementDepthResolver();

        self::assertSame(5, $resolver->levelForEventName(UsageEventName::ANALYSIS_EXPLORER_EXPORTED_CSV));
        self::assertSame(3, $resolver->levelForEventName(UsageEventName::ANALYSIS_EXPLORER_RUN));
        self::assertSame(2, $resolver->levelForEventName(UsageEventName::IMPORT_COMPLETED));
        self::assertSame(2, $resolver->levelForEventName(UsageEventName::ANALYSIS_LIBRARY_OPENED));
        self::assertSame(2, $resolver->levelForEventName(UsageEventName::ANALYSIS_EXPLORER_OPENED));
        self::assertSame(2, $resolver->levelForEventName(UsageEventName::BENCHMARKING_OPENED));
        self::assertSame(2, $resolver->levelForEventName(UsageEventName::EXPLORE_ALLOCATION_OPENED));
        self::assertSame(2, $resolver->levelForEventName(UsageEventName::IMPORT_STARTED));
        self::assertSame(0, $resolver->levelForEventName(UsageEventName::USER_REGISTERED));
        self::assertSame(0, $resolver->levelForEventName(UsageEventName::USER_BECAME_PARTICIPANT));
        self::assertSame(0, $resolver->levelForEventName('unknown.event'));
    }

    public function testFeatureAreaLevels(): void
    {
        $resolver = new EngagementDepthResolver();

        self::assertSame(1, $resolver->levelForFeatureArea('dashboard'));
        self::assertSame(2, $resolver->levelForFeatureArea('analysis'));
        self::assertSame(2, $resolver->levelForFeatureArea('statistics'));
        self::assertSame(2, $resolver->levelForFeatureArea('explore'));
        self::assertSame(2, $resolver->levelForFeatureArea('import'));
        self::assertSame(5, $resolver->levelForFeatureArea('export'));
        self::assertSame(0, $resolver->levelForFeatureArea('other'));
    }

    public function testNoSourceProducesTheRemovedLevelFour(): void
    {
        $resolver = new EngagementDepthResolver();

        foreach (['home', 'dashboard', 'statistics', 'analysis', 'explore', 'import', 'export', 'admin', 'blog', 'pages', 'other'] as $area) {
            self::assertNotSame(4, $resolver->levelForFeatureArea($area), $area);
        }
    }
}
