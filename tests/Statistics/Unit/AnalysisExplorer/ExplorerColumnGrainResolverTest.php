<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerColumnGrainResolver;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Tests\Statistics\Support\AnalysisExplorerTestSupport;
use PHPUnit\Framework\TestCase;

final class ExplorerColumnGrainResolverTest extends TestCase
{
    use AnalysisExplorerTestSupport;

    private ExplorerColumnGrainResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ExplorerColumnGrainResolver();
    }

    public function testBreakdownColumnAlwaysUsesTotal(): void
    {
        $capabilities = $this->createAllocationsCapabilitiesProvider()->capabilities();
        $rowAxis = AnalysisAxisRef::time(AnalysisDimensionGrain::Month);

        self::assertSame(
            AnalysisDimensionGrain::Total,
            $this->resolver->resolve(
                $rowAxis,
                AnalysisDimensionKey::Gender,
                AnalysisDimensionGrain::Month,
                $capabilities,
            ),
        );
        self::assertFalse($this->resolver->affectsQuery(AnalysisDimensionKey::Gender));
    }

    public function testTimeColumnWithBreakdownRowDefaultsToDefaultTimeGrain(): void
    {
        $capabilities = $this->createAllocationsCapabilitiesProvider()->capabilities();
        $rowAxis = AnalysisAxisRef::breakdown(AnalysisDimensionKey::Department);

        self::assertSame(
            AnalysisDimensionGrain::Month,
            $this->resolver->resolve($rowAxis, AnalysisDimensionKey::Time, null, $capabilities),
        );
    }

    public function testTimeColumnWithBreakdownRowKeepsValidSubmittedGrain(): void
    {
        $capabilities = $this->createAllocationsCapabilitiesProvider()->capabilities();
        $rowAxis = AnalysisAxisRef::breakdown(AnalysisDimensionKey::Department);

        self::assertSame(
            AnalysisDimensionGrain::Year,
            $this->resolver->resolve(
                $rowAxis,
                AnalysisDimensionKey::Time,
                AnalysisDimensionGrain::Year,
                $capabilities,
            ),
        );
    }

    public function testTimeColumnWithTemporalRowMatchesRowGrain(): void
    {
        $capabilities = $this->createAllocationsCapabilitiesProvider()->capabilities();
        $rowAxis = AnalysisAxisRef::time(AnalysisDimensionGrain::Year);

        self::assertSame(
            AnalysisDimensionGrain::Year,
            $this->resolver->defaultFor($rowAxis, AnalysisDimensionKey::Time, $capabilities),
        );
    }
}
