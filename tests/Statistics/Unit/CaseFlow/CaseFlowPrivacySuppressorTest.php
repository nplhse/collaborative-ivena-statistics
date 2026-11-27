<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\CaseFlow;

use App\Statistics\Application\Mapping\AllocationStatsHospitalTierProjectionCode;
use App\Statistics\CaseFlow\Application\CaseFlowPrivacyPolicy;
use App\Statistics\CaseFlow\Application\CaseFlowPrivacySuppressor;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowMapFeature;
use App\Statistics\CaseFlow\Infrastructure\Query\Dto\CaseFlowDestinationPoolRow;
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

    public function testBuildMapFeaturesLeavesOutSmallCells(): void
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

        self::assertCount(1, $features);
        self::assertFalse($features[0]->suppressed);
        self::assertSame(50, $features[0]->caseCount);
        self::assertSame('frankfurt', $features[0]->geoFeatureKey);
        // 50 of 55: the share stays against all origin rows, including the one left out.
        self::assertSame(90.9, $features[0]->sharePercent);
    }

    /**
     * #1767: A = 50, B = 30, C = 4. The origin bar shows "Other" = 4; if the map named C as suppressed, that would
     * be C's exact count. The map must not mention C at all.
     */
    public function testASingleSmallOriginCannotBeMatchedToTheOtherBar(): void
    {
        $rows = [
            new CaseFlowOriginRow(1, 'Kassel', 50, 10),
            new CaseFlowOriginRow(2, 'Marburg', 30, 5),
            new CaseFlowOriginRow(3, 'Fulda', 4, 1),
        ];

        $slices = $this->suppressor->suppressOriginDistribution($rows, 84);
        $features = $this->suppressor->buildMapFeatures(
            $rows,
            84,
            static fn (int $id, string $name): string => strtolower($name),
        );

        self::assertSame(CaseFlowPrivacyPolicy::OTHER_ORIGIN_KEY, $slices[2]->originName);
        self::assertSame(4, $slices[2]->caseCount);
        self::assertSame(['Kassel', 'Marburg'], array_map(static fn (CaseFlowMapFeature $feature): string => $feature->originName, $features));
        self::assertNotContains(3, array_map(static fn (CaseFlowMapFeature $feature): int => $feature->dispatchAreaId, $features));
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

    public function testSuppressDestinationPoolsNeedsThreeHospitalsPerPool(): void
    {
        // Both pools have enough cases; only the hospital count decides (#934).
        $rows = [
            new CaseFlowDestinationPoolRow('3', 40, 3),
            new CaseFlowDestinationPoolRow('1', 25, 2),
        ];

        /** @var array<string, string> $labelKeysByPoolKey */
        $labelKeysByPoolKey = [
            (string) AllocationStatsHospitalTierProjectionCode::Full->value => 'stats.case_flow.tier.full',
            (string) AllocationStatsHospitalTierProjectionCode::Basic->value => 'stats.case_flow.tier.basic',
        ];

        $slices = $this->suppressor->suppressDestinationPools($rows, $labelKeysByPoolKey);

        self::assertCount(2, $slices);
        self::assertSame((string) AllocationStatsHospitalTierProjectionCode::Full->value, $slices[0]->poolKey);
        self::assertSame(40, $slices[0]->caseCount);
        self::assertSame(CaseFlowPrivacyPolicy::SUPPRESSED_POOL_KEY, $slices[1]->poolKey);
        self::assertSame(25, $slices[1]->caseCount);
    }

    public function testSuppressDestinationHospitalsDropsSmallNPins(): void
    {
        $rows = [
            new \App\Statistics\GeographicMap\Infrastructure\Query\GeographicDestinationHospitalRow(
                1,
                'Large Hospital',
                50.1,
                8.6,
                20,
                3,
                1,
            ),
            new \App\Statistics\GeographicMap\Infrastructure\Query\GeographicDestinationHospitalRow(
                2,
                'Small Hospital',
                50.2,
                8.7,
                4,
                1,
                1,
            ),
        ];

        $pinSet = $this->suppressor->suppressDestinationHospitals($rows, 24);

        self::assertCount(1, $pinSet->pins);
        self::assertSame('Large Hospital', $pinSet->pins[0]->name);
        self::assertSame(20, $pinSet->pins[0]->caseCount);
        self::assertFalse($pinSet->pins[0]->suppressed);
        self::assertTrue($pinSet->pins[0]->insideSelectedArea);
        self::assertSame(1, $pinSet->omittedInsideCount);
        self::assertSame(0, $pinSet->omittedOutsideCount);
    }

    public function testSuppressDestinationHospitalsMarksOutflowPins(): void
    {
        $rows = [
            new \App\Statistics\GeographicMap\Infrastructure\Query\GeographicDestinationHospitalRow(
                1,
                'Inside Hospital',
                50.1,
                8.6,
                20,
                3,
                1,
                10,
            ),
            new \App\Statistics\GeographicMap\Infrastructure\Query\GeographicDestinationHospitalRow(
                2,
                'Outside Hospital',
                50.2,
                8.7,
                12,
                3,
                1,
                99,
            ),
        ];

        $pinSet = $this->suppressor->suppressDestinationHospitals($rows, 32, 10);

        self::assertCount(2, $pinSet->pins);
        self::assertTrue($pinSet->pins[0]->insideSelectedArea);
        self::assertFalse($pinSet->pins[1]->insideSelectedArea);
        self::assertSame(0, $pinSet->omittedInsideCount);
        self::assertSame(0, $pinSet->omittedOutsideCount);
    }

    public function testSuppressDestinationHospitalsCountsOmittedOutflowPins(): void
    {
        $rows = [
            new \App\Statistics\GeographicMap\Infrastructure\Query\GeographicDestinationHospitalRow(
                1,
                'Inside Hospital',
                50.1,
                8.6,
                20,
                3,
                1,
                10,
            ),
            new \App\Statistics\GeographicMap\Infrastructure\Query\GeographicDestinationHospitalRow(
                2,
                'Small Outside Hospital',
                50.2,
                8.7,
                4,
                3,
                1,
                99,
            ),
            new \App\Statistics\GeographicMap\Infrastructure\Query\GeographicDestinationHospitalRow(
                3,
                'No Coordinates Outside',
                null,
                null,
                40,
                3,
                1,
                99,
            ),
        ];

        $pinSet = $this->suppressor->suppressDestinationHospitals($rows, 64, 10);

        self::assertCount(1, $pinSet->pins);
        self::assertTrue($pinSet->pins[0]->insideSelectedArea);
        self::assertSame(0, $pinSet->omittedInsideCount);
        self::assertSame(2, $pinSet->omittedOutsideCount);
    }

    public function testSummarizeUnshapedOriginsCountsCasesWithoutPolygon(): void
    {
        // 100 cases in the denominator; Frankfurt (50) and Darmstadt (5, suppressed on the map) have a polygon.
        // Mainz (30) and Aschaffenburg (15) have none: 45 of 100 cases = 45.0 % are not drawn.
        $rows = [
            new CaseFlowOriginRow(1, 'Frankfurt', 50, 10),
            new CaseFlowOriginRow(3, 'Mainz', 30, 5),
            new CaseFlowOriginRow(4, 'Aschaffenburg', 15, 2),
            new CaseFlowOriginRow(2, 'Darmstadt', 5, 1),
        ];

        $unshaped = $this->suppressor->summarizeUnshapedOrigins(
            $rows,
            100,
            static fn (int $id, string $name): string => strtolower($name),
            static fn (string $key): bool => \in_array($key, ['frankfurt', 'darmstadt'], true),
        );

        self::assertFalse($unshaped->suppressed);
        self::assertFalse($unshaped->isEmpty());
        self::assertSame(45, $unshaped->caseCount);
        self::assertSame(45.0, $unshaped->sharePercent);
    }

    public function testSummarizeUnshapedOriginsWithholdsCountBelowPrivacyThreshold(): void
    {
        // Mainz (4) + Aschaffenburg (5) = 9 cases without polygon, below MIN_CASES_PER_CELL (10).
        $rows = [
            new CaseFlowOriginRow(1, 'Frankfurt', 91, 10),
            new CaseFlowOriginRow(4, 'Aschaffenburg', 5, 1),
            new CaseFlowOriginRow(3, 'Mainz', 4, 1),
        ];

        $unshaped = $this->suppressor->summarizeUnshapedOrigins(
            $rows,
            100,
            static fn (int $id, string $name): string => strtolower($name),
            static fn (string $key): bool => 'frankfurt' === $key,
        );

        self::assertTrue($unshaped->suppressed);
        self::assertFalse($unshaped->isEmpty());
        self::assertSame(0, $unshaped->caseCount);
        self::assertSame(0.0, $unshaped->sharePercent);
    }

    public function testSummarizeUnshapedOriginsIsEmptyWhenEveryOriginHasPolygon(): void
    {
        $rows = [
            new CaseFlowOriginRow(1, 'Frankfurt', 60, 10),
            new CaseFlowOriginRow(2, 'Darmstadt', 40, 5),
        ];

        $unshaped = $this->suppressor->summarizeUnshapedOrigins(
            $rows,
            100,
            static fn (int $id, string $name): string => strtolower($name),
            static fn (string $key): bool => true,
        );

        self::assertTrue($unshaped->isEmpty());
    }
}
