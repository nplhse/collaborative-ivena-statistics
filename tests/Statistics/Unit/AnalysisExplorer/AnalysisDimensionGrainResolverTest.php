<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\AnalysisDimensionGrainResolver;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Tests\Statistics\Support\AnalysisExplorerTestSupport;
use PHPUnit\Framework\TestCase;

final class AnalysisDimensionGrainResolverTest extends TestCase
{
    use AnalysisExplorerTestSupport;

    private AnalysisDimensionGrainResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new AnalysisDimensionGrainResolver();
    }

    public function testTimeDimensionDefaultsToMonthWhenGrainMissing(): void
    {
        $grain = $this->resolver->resolveFromString(
            AnalysisDimensionKey::Time,
            null,
            $this->createAllocationsCapabilitiesProvider()->capabilities(),
        );

        self::assertSame(AnalysisDimensionGrain::Month, $grain);
    }

    public function testTimeDimensionRejectsInvalidGrain(): void
    {
        $grain = $this->resolver->resolveFromString(
            AnalysisDimensionKey::Time,
            'total',
            $this->createAllocationsCapabilitiesProvider()->capabilities(),
        );

        self::assertSame(AnalysisDimensionGrain::Month, $grain);
    }

    public function testGenderWithoutGrainDefaultsToTotal(): void
    {
        $grain = $this->resolver->resolveFromString(
            AnalysisDimensionKey::Gender,
            null,
            $this->createAllocationsCapabilitiesProvider()->capabilities(),
        );

        self::assertSame(AnalysisDimensionGrain::Total, $grain);
    }

    public function testGenderWithInvalidGrainDefaultsToTotal(): void
    {
        $grain = $this->resolver->resolveFromString(
            AnalysisDimensionKey::Gender,
            'invalid',
            $this->createAllocationsCapabilitiesProvider()->capabilities(),
        );

        self::assertSame(AnalysisDimensionGrain::Total, $grain);
    }

    public function testUrgencyKeepsValidTemporalGrain(): void
    {
        $grain = $this->resolver->resolveFromString(
            AnalysisDimensionKey::Urgency,
            'year',
            $this->createAllocationsCapabilitiesProvider()->capabilities(),
        );

        self::assertSame(AnalysisDimensionGrain::Year, $grain);
    }
}
