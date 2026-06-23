<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerMetricSummabilityPolicy;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use PHPUnit\Framework\TestCase;

final class ExplorerMetricSummabilityPolicyTest extends TestCase
{
    private ExplorerMetricSummabilityPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new ExplorerMetricSummabilityPolicy();
    }

    public function testCountAndDistributionMetricsAreSummable(): void
    {
        self::assertTrue($this->policy->isSummable(AnalysisMetricKey::HospitalCount));
        self::assertTrue($this->policy->isSummable(AnalysisMetricKey::PercentOfTotal));
    }

    public function testAdditiveNumericAggregatesAreSummable(): void
    {
        self::assertTrue($this->policy->isSummable(AnalysisMetricKey::SumBeds));
        self::assertTrue($this->policy->isSummable(AnalysisMetricKey::TotalAllocations));
    }

    public function testNonAdditiveNumericAggregatesAreNotSummable(): void
    {
        self::assertFalse($this->policy->isSummable(AnalysisMetricKey::AvgBeds));
        self::assertFalse($this->policy->isSummable(AnalysisMetricKey::AvgAllocationsPerHospital));
        self::assertFalse($this->policy->isSummable(AnalysisMetricKey::MinBeds));
        self::assertFalse($this->policy->isSummable(AnalysisMetricKey::MaxAllocations));
    }
}
