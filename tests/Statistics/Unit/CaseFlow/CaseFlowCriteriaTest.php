<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\CaseFlow;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowCriteria;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowMode;
use PHPUnit\Framework\TestCase;

final class CaseFlowCriteriaTest extends TestCase
{
    public function testDetectsSingleHospitalAndDispatchAreaScopes(): void
    {
        $hospital = $this->criteria(
            new StatisticsFilter(StatisticsFilterScope::Hospital, 12, null, StatisticsFilterPeriod::All),
        );
        $dispatchArea = $this->criteria(
            new StatisticsFilter(
                StatisticsFilterScope::DispatchArea,
                null,
                null,
                StatisticsFilterPeriod::All,
                dispatchAreaId: 15,
            ),
        );
        $public = $this->criteria(
            new StatisticsFilter(StatisticsFilterScope::Public, null, null, StatisticsFilterPeriod::All),
        );

        self::assertTrue($hospital->isSingleHospital());
        self::assertFalse($hospital->isDispatchAreaScope());
        self::assertTrue($dispatchArea->isDispatchAreaScope());
        self::assertFalse($dispatchArea->isSingleHospital());
        self::assertFalse($public->isSingleHospital());
        self::assertFalse($public->isDispatchAreaScope());
        self::assertNull($hospital->originStateId());
    }

    public function testOriginStateIdIsSetOnlyForStateScope(): void
    {
        $state = $this->criteria(
            new StatisticsFilter(
                StatisticsFilterScope::State,
                null,
                null,
                StatisticsFilterPeriod::All,
                stateId: 7,
            ),
        );

        self::assertSame(7, $state->originStateId());
    }

    private function criteria(StatisticsFilter $filter): CaseFlowCriteria
    {
        return new CaseFlowCriteria(
            $filter,
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(null),
            CaseFlowMode::SystemFlow,
        );
    }
}
