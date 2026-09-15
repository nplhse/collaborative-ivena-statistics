<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\CaseFlow;

use App\Statistics\Application\Mapping\AllocationStatsHospitalTierProjectionCode;
use App\Statistics\CaseFlow\Application\CaseFlowDistributionBuilder;
use App\Statistics\CaseFlow\Infrastructure\Query\Dto\CaseFlowBucketRow;
use PHPUnit\Framework\TestCase;

final class CaseFlowDistributionBuilderTest extends TestCase
{
    private CaseFlowDistributionBuilder $builder;

    #[\Override]
    protected function setUp(): void
    {
        $this->builder = new CaseFlowDistributionBuilder();
    }

    public function testBuildTransportTimeOrdersDisplayBucketsAndAggregatesDuplicates(): void
    {
        $rows = [
            new CaseFlowBucketRow('20_30', 10),
            new CaseFlowBucketRow('20_30', 5),
            new CaseFlowBucketRow('unknown', 99),
        ];

        $slices = $this->builder->buildTransportTime($rows, 15);

        self::assertCount(7, $slices);
        self::assertSame('under_10', $slices[0]->key);
        self::assertSame(0, $slices[0]->count);
        self::assertSame('20_30', $slices[2]->key);
        self::assertSame(15, $slices[2]->count);
        self::assertSame(100.0, $slices[2]->percent);
    }

    public function testTierLabelKeysUseStringProjectionCodes(): void
    {
        $keys = $this->builder->tierLabelKeys();

        self::assertArrayHasKey(
            (string) AllocationStatsHospitalTierProjectionCode::Full->value,
            $keys,
        );
        self::assertArrayHasKey(
            (string) AllocationStatsHospitalTierProjectionCode::Basic->value,
            $keys,
        );
    }
}
