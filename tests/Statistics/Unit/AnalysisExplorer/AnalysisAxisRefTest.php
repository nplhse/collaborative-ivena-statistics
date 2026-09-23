<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Exception\InvalidExplorerConfigException;
use PHPUnit\Framework\TestCase;

final class AnalysisAxisRefTest extends TestCase
{
    public function testTimeTotalUsesTheAllocationTotalRegistryKey(): void
    {
        $axis = AnalysisAxisRef::time(AnalysisDimensionGrain::Total);

        self::assertSame('allocation_total', $axis->toRegistryKey());
    }

    public function testFromStateArrayRejectsUnknownDimensionsAndGrains(): void
    {
        try {
            AnalysisAxisRef::fromStateArray(['dimension' => '']);
            self::fail('An empty dimension must fail.');
        } catch (InvalidExplorerConfigException) {
        }

        try {
            AnalysisAxisRef::fromStateArray(['dimension' => 'not_a_dimension']);
            self::fail('An unknown dimension must fail.');
        } catch (InvalidExplorerConfigException) {
        }

        $this->expectException(InvalidExplorerConfigException::class);
        AnalysisAxisRef::fromStateArray([
            'dimension' => AnalysisDimensionKey::Time->value,
            'grain' => 'not_a_grain',
        ]);
    }
}
