<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\CaseFlow;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Statistics\Application\Cohort\HospitalCohortKey;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\CaseFlow\Application\CaseFlowModeResolver;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowMode;
use PHPUnit\Framework\TestCase;

final class CaseFlowModeResolverTest extends TestCase
{
    private CaseFlowModeResolver $resolver;

    #[\Override]
    protected function setUp(): void
    {
        $this->resolver = new CaseFlowModeResolver();
    }

    public function testResolvesHospitalOriginForHospitalScope(): void
    {
        $filter = new StatisticsFilter(StatisticsFilterScope::Hospital, 1, null, StatisticsFilterPeriod::All);

        self::assertSame(CaseFlowMode::HospitalOrigin, $this->resolver->resolve($filter));
    }

    public function testResolvesHospitalOriginForMyHospitalsScope(): void
    {
        $filter = new StatisticsFilter(StatisticsFilterScope::MyHospitals, null, null, StatisticsFilterPeriod::All);

        self::assertSame(CaseFlowMode::HospitalOrigin, $this->resolver->resolve($filter));
    }

    public function testResolvesSystemFlowForPublicScope(): void
    {
        $filter = new StatisticsFilter(StatisticsFilterScope::Public, null, null, StatisticsFilterPeriod::All);

        self::assertSame(CaseFlowMode::SystemFlow, $this->resolver->resolve($filter));
    }

    public function testResolvesSystemFlowForHospitalCohortScope(): void
    {
        $filter = new StatisticsFilter(
            StatisticsFilterScope::HospitalCohort,
            null,
            new HospitalCohortKey(HospitalLocation::URBAN, HospitalTier::FULL),
            StatisticsFilterPeriod::All,
        );

        self::assertSame(CaseFlowMode::SystemFlow, $this->resolver->resolve($filter));
    }

    public function testResolvesSystemFlowForDispatchAreaScope(): void
    {
        $filter = new StatisticsFilter(
            StatisticsFilterScope::DispatchArea,
            null,
            null,
            StatisticsFilterPeriod::All,
            dispatchAreaId: 15,
        );

        self::assertSame(CaseFlowMode::SystemFlow, $this->resolver->resolve($filter));
    }
}
