<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerMetricKeyMapper;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use PHPUnit\Framework\TestCase;

final class ExplorerMetricKeyMapperTest extends TestCase
{
    private ExplorerMetricKeyMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new ExplorerMetricKeyMapper();
    }

    public function testToRegistryKeyMapsAllocationCountToCount(): void
    {
        self::assertSame('count', $this->mapper->toRegistryKey(AnalysisMetricKey::AllocationCount));
    }

    public function testToRegistryKeyKeepsOtherMetricValues(): void
    {
        self::assertSame('resus_rate', $this->mapper->toRegistryKey(AnalysisMetricKey::ResusRate));
    }

    public function testToRegistryKeysPreservesOrder(): void
    {
        self::assertSame(
            ['count', 'percent_of_total'],
            $this->mapper->toRegistryKeys([
                AnalysisMetricKey::AllocationCount,
                AnalysisMetricKey::PercentOfTotal,
            ]),
        );
    }

    public function testToExplorerKeyMapsCountRegistryKey(): void
    {
        self::assertSame(AnalysisMetricKey::AllocationCount, $this->mapper->toExplorerKey('count'));
    }

    public function testToExplorerKeyMapsNamedRegistryKeys(): void
    {
        self::assertSame(AnalysisMetricKey::ResusRate, $this->mapper->toExplorerKey('resus_rate'));
    }

    public function testToExplorerKeyReturnsNullForUnknownRegistryKey(): void
    {
        self::assertNull($this->mapper->toExplorerKey('unknown_metric'));
    }

    public function testToExplorerKeysSkipsUnknownRegistryKeys(): void
    {
        self::assertSame(
            [AnalysisMetricKey::AllocationCount, AnalysisMetricKey::PercentOfTotal],
            $this->mapper->toExplorerKeys(['count', 'unknown_metric', 'percent_of_total']),
        );
    }
}
