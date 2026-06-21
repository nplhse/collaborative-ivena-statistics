<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\AllocationsCapabilitiesProvider;
use App\Statistics\AnalysisExplorer\Application\AnalysisDimensionGrainResolver;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use PHPUnit\Framework\TestCase;

final class AnalysisDimensionGrainResolverTest extends TestCase
{
    private AnalysisDimensionGrainResolver $resolver;

    private AllocationsCapabilitiesProvider $capabilitiesProvider;

    protected function setUp(): void
    {
        $this->resolver = new AnalysisDimensionGrainResolver();
        $this->capabilitiesProvider = new AllocationsCapabilitiesProvider();
    }

    public function testTimeDimensionDefaultsToMonthWhenGrainMissing(): void
    {
        $grain = $this->resolver->resolveFromString(
            AnalysisDimensionKey::Time,
            null,
            $this->capabilitiesProvider->capabilities(),
        );

        self::assertSame(AnalysisDimensionGrain::Month, $grain);
    }

    public function testTimeDimensionRejectsInvalidGrain(): void
    {
        $grain = $this->resolver->resolveFromString(
            AnalysisDimensionKey::Time,
            'total',
            $this->capabilitiesProvider->capabilities(),
        );

        self::assertSame(AnalysisDimensionGrain::Month, $grain);
    }

    public function testGenderWithoutGrainDefaultsToTotal(): void
    {
        $grain = $this->resolver->resolveFromString(
            AnalysisDimensionKey::Gender,
            null,
            $this->capabilitiesProvider->capabilities(),
        );

        self::assertSame(AnalysisDimensionGrain::Total, $grain);
    }

    public function testGenderWithInvalidGrainDefaultsToTotal(): void
    {
        $grain = $this->resolver->resolveFromString(
            AnalysisDimensionKey::Gender,
            'invalid',
            $this->capabilitiesProvider->capabilities(),
        );

        self::assertSame(AnalysisDimensionGrain::Total, $grain);
    }

    public function testUrgencyKeepsValidTemporalGrain(): void
    {
        $grain = $this->resolver->resolveFromString(
            AnalysisDimensionKey::Urgency,
            'year',
            $this->capabilitiesProvider->capabilities(),
        );

        self::assertSame(AnalysisDimensionGrain::Year, $grain);
    }
}
