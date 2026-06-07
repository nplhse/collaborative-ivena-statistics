<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\CaseFlow;

use App\Statistics\Application\Mapping\AllocationStatsHospitalTierProjectionCode;
use App\Statistics\CaseFlow\Application\CaseFlowPrivacyPolicy;
use App\Statistics\CaseFlow\Application\CaseFlowPrivacySuppressor;
use App\Statistics\CaseFlow\Infrastructure\Query\Dto\CaseFlowDestinationPoolRow;
use App\Statistics\CaseFlow\Infrastructure\Query\Dto\CaseFlowFlowMatrixCell;
use App\Statistics\CaseFlow\Infrastructure\Query\Dto\CaseFlowOriginRow;
use PHPUnit\Framework\TestCase;

final class CaseFlowPrivacySuppressorTest extends TestCase
{
    private CaseFlowPrivacySuppressor $suppressor;

    #[\Override]
    protected function setUp(): void
    {
        $this->suppressor = new CaseFlowPrivacySuppressor();
    }

    public function testSuppressOriginDistributionMergesSmallGroups(): void
    {
        $rows = [
            new CaseFlowOriginRow(1, 'Frankfurt', 100, 40),
            new CaseFlowOriginRow(2, 'Darmstadt', 5, 1),
            new CaseFlowOriginRow(3, 'Kassel', 8, 2),
        ];

        $slices = $this->suppressor->suppressOriginDistribution($rows, 113);

        self::assertCount(2, $slices);
        self::assertSame('Frankfurt', $slices[0]->originName);
        self::assertSame(CaseFlowPrivacyPolicy::OTHER_ORIGIN_KEY, $slices[1]->originName);
        self::assertSame(13, $slices[1]->caseCount);
    }

    public function testSuppressFlowMatrixHidesUnderThresholdPools(): void
    {
        $cells = [
            new CaseFlowFlowMatrixCell(1, 'Frankfurt', 3, 50, 3),
            new CaseFlowFlowMatrixCell(1, 'Frankfurt', 1, 5, 1),
            new CaseFlowFlowMatrixCell(2, 'Kassel', 3, 20, 2),
        ];

        $matrix = $this->suppressor->suppressFlowMatrix($cells);

        self::assertCount(2, $matrix);
        self::assertSame(55, $matrix[0]->totalCases);
        self::assertCount(2, $matrix[0]->destinationCounts);
        self::assertContains(50, array_values($matrix[0]->destinationCounts));
        self::assertContains(5, array_values($matrix[0]->destinationCounts));
        self::assertArrayHasKey(CaseFlowPrivacyPolicy::SUPPRESSED_POOL_KEY, $matrix[0]->destinationCounts);
    }

    public function testBuildMapFeaturesSuppressesSmallCells(): void
    {
        $rows = [
            new CaseFlowOriginRow(1, 'Frankfurt', 50, 10),
            new CaseFlowOriginRow(2, 'Darmstadt', 5, 1),
        ];

        $features = $this->suppressor->buildMapFeatures(
            $rows,
            55,
            static fn (int $id, string $name): string => strtolower($name),
        );

        self::assertCount(2, $features);
        self::assertFalse($features[0]->suppressed);
        self::assertSame(50, $features[0]->caseCount);
        self::assertSame('frankfurt', $features[0]->geoFeatureKey);
        self::assertTrue($features[1]->suppressed);
        self::assertSame(0, $features[1]->caseCount);
        self::assertSame(0.0, $features[1]->sharePercent);
    }

    public function testSuppressDestinationPoolsMergesSmallPools(): void
    {
        $rows = [
            new CaseFlowDestinationPoolRow('3', 100, 5),
            new CaseFlowDestinationPoolRow('1', 5, 1),
        ];

        /** @var array<string, string> $labelKeysByPoolKey */
        $labelKeysByPoolKey = [
            (string) AllocationStatsHospitalTierProjectionCode::Full->value => 'stats.case_flow.tier.full',
            (string) AllocationStatsHospitalTierProjectionCode::Basic->value => 'stats.case_flow.tier.basic',
        ];

        $slices = $this->suppressor->suppressDestinationPools($rows, $labelKeysByPoolKey);

        self::assertCount(2, $slices);
        self::assertSame((string) AllocationStatsHospitalTierProjectionCode::Full->value, $slices[0]->poolKey);
        self::assertSame(CaseFlowPrivacyPolicy::SUPPRESSED_POOL_KEY, $slices[1]->poolKey);
    }
}
