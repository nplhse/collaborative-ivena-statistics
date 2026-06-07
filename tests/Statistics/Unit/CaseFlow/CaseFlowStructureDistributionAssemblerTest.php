<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\CaseFlow;

use App\Statistics\Application\Mapping\AllocationStatsHospitalTierProjectionCode;
use App\Statistics\CaseFlow\Application\CaseFlowPrivacyPolicy;
use App\Statistics\CaseFlow\Application\CaseFlowStructureDistributionAssembler;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowDestinationPoolSlice;
use PHPUnit\Framework\TestCase;

final class CaseFlowStructureDistributionAssemblerTest extends TestCase
{
    private CaseFlowStructureDistributionAssembler $assembler;

    #[\Override]
    protected function setUp(): void
    {
        $this->assembler = new CaseFlowStructureDistributionAssembler();
    }

    public function testTierCardBuildsOrderedSegmentsWithSuppressedPool(): void
    {
        $fullTierKey = (string) AllocationStatsHospitalTierProjectionCode::Full->value;
        $basicTierKey = (string) AllocationStatsHospitalTierProjectionCode::Basic->value;

        $card = $this->assembler->tierCard([
            new CaseFlowDestinationPoolSlice($fullTierKey, 'stats.case_flow.tier.full', 80, 4, false),
            new CaseFlowDestinationPoolSlice(CaseFlowPrivacyPolicy::SUPPRESSED_POOL_KEY, 'stats.case_flow.pool.suppressed', 20, 1, true),
        ]);

        self::assertSame('stats.case_flow.section.destination_tier', $card->titleTranslationKey);
        self::assertCount(2, $card->segments);
        self::assertSame(80, $card->segments[0]->count);
        self::assertSame(80.0, $card->segments[0]->percent);
        self::assertSame(20, $card->segments[1]->count);
        self::assertSame('stats.case_flow.pool.suppressed', $card->segments[1]->labelTranslationKey);
    }

    public function testSizeCardSkipsZeroCountSegmentsExceptSuppressed(): void
    {
        $card = $this->assembler->sizeCard([
            new CaseFlowDestinationPoolSlice('Large', 'hospital.size.Large', 50, 2, false),
            new CaseFlowDestinationPoolSlice(CaseFlowPrivacyPolicy::SUPPRESSED_POOL_KEY, 'stats.case_flow.pool.suppressed', 0, 0, true),
        ]);

        self::assertCount(2, $card->segments);
        self::assertSame('hospital.size.Large', $card->segments[0]->labelTranslationKey);
        self::assertSame(0, $card->segments[1]->count);
    }
}
