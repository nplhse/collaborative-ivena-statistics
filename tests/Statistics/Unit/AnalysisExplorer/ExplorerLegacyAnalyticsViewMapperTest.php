<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerLegacyAnalyticsViewMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExplorerLegacyAnalyticsViewMapperTest extends TestCase
{
    private ExplorerLegacyAnalyticsViewMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new ExplorerLegacyAnalyticsViewMapper();
    }

    #[DataProvider('mappedViewKeysProvider')]
    public function testSlugForLegacyViewKeyMapsKnownPresets(string $viewKey, string $expectedSlug): void
    {
        self::assertSame($expectedSlug, $this->mapper->slugForLegacyViewKey($viewKey));
    }

    public function testSlugForLegacyViewKeyReturnsNullForUnknownPreset(): void
    {
        self::assertNull($this->mapper->slugForLegacyViewKey('unknown_preset'));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function mappedViewKeysProvider(): iterable
    {
        yield 'allocations by month' => ['allocations_by_month', 'allocations-over-time'];
        yield 'allocations by hour' => ['allocations_by_hour', 'day-time-bucket-distribution'];
        yield 'gender distribution' => ['gender_distribution', 'gender-distribution'];
    }
}
