<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Panel\Distribution;

use App\Statistics\Application\Panel\Distribution\DistributionSectionNavProvider;
use PHPUnit\Framework\TestCase;

final class DistributionSectionNavProviderTest extends TestCase
{
    public function testSectionsContainAllDistributionRoutesInOrder(): void
    {
        $sections = new DistributionSectionNavProvider()->sections();

        $routes = array_column($sections, 'route');

        self::assertSame([
            'app_stats_distribution_urgency',
            'app_stats_distribution_gender',
            'app_stats_distribution_age',
            'app_stats_distribution_assignment',
            'app_stats_distribution_occasion',
            'app_stats_distribution_time',
            'app_stats_distribution_transport_time',
            'app_stats_distribution_resources',
            'app_stats_distribution_traits',
        ], $routes);
    }

    public function testEachSectionHasTranslationLabelKey(): void
    {
        foreach (new DistributionSectionNavProvider()->sections() as $row) {
            self::assertArrayHasKey('label', $row);
            self::assertStringStartsWith('statistics.distribution.section.', $row['label']);
        }
    }
}
