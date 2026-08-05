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
        self::assertSame(0, $resolver->levelForEventName(UsageEventName::ONBOARDING_STEP_COMPLETED));
        self::assertSame(0, $resolver->levelForEventName('unknown.event'));
    }

    public function testFeatureAreaLevels(): void
    {
        $resolver = new EngagementDepthResolver();

        self::assertSame(1, $resolver->levelForFeatureArea('dashboard', false));
        self::assertSame(2, $resolver->levelForFeatureArea('analysis', false));
        self::assertSame(4, $resolver->levelForFeatureArea('analysis', true));
        self::assertSame(2, $resolver->levelForFeatureArea('statistics', false));
        self::assertSame(4, $resolver->levelForFeatureArea('statistics', true));
        self::assertSame(2, $resolver->levelForFeatureArea('explore', false));
        self::assertSame(2, $resolver->levelForFeatureArea('import', true));
        self::assertSame(5, $resolver->levelForFeatureArea('export', false));
        self::assertSame(0, $resolver->levelForFeatureArea('other', false));
    }
}
