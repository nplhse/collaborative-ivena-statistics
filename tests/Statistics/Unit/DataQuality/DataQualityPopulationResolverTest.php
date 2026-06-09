<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\DataQuality;

use App\Statistics\Application\Cohort\HospitalCohortKey;
use App\Statistics\Application\Cohort\HospitalCohortResolver;
use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\DataQuality\Application\Contract\DataQualityHospitalPopulationReaderInterface;
use App\Statistics\DataQuality\Application\DataQualityPopulationResolver;
use App\Statistics\DataQuality\Dto\DataQualityHospitalSnapshot;
use PHPUnit\Framework\TestCase;

final class DataQualityPopulationResolverTest extends TestCase
{
    public function testResolvesPublicScopeToAllHospitals(): void
    {
        $snapshot = new DataQualityHospitalSnapshot(1, 'Large', 'Full', 'Urban', 'A');
        $query = $this->createMock(DataQualityHospitalPopulationReaderInterface::class);
        $query->expects(self::once())->method('fetchAll')->willReturn([$snapshot]);

        $resolver = new DataQualityPopulationResolver(
            $query,
            new HospitalCohortResolver(),
            $this->createMock(HospitalAccessInterface::class),
        );

        $filter = new StatisticsFilter(StatisticsFilterScope::Public, null, null, StatisticsFilterPeriod::All);
        $result = $resolver->resolve($filter, null);

        self::assertSame([$snapshot], $result);
    }

    public function testResolvesStateScopeByStateId(): void
    {
        $query = $this->createMock(DataQualityHospitalPopulationReaderInterface::class);
        $query->expects(self::once())->method('fetchByStateId')->with(7)->willReturn([]);

        $resolver = new DataQualityPopulationResolver(
            $query,
            new HospitalCohortResolver(),
            $this->createMock(HospitalAccessInterface::class),
        );

        $filter = new StatisticsFilter(
            StatisticsFilterScope::State,
            null,
            null,
            StatisticsFilterPeriod::Year,
            stateId: 7,
        );

        self::assertSame([], $resolver->resolve($filter, null));
    }

    public function testResolvesMyHospitalsScopeFromAccessInterface(): void
    {
        $snapshot = new DataQualityHospitalSnapshot(5, 'Small', 'Basic', 'Rural', 'B');
        $query = $this->createMock(DataQualityHospitalPopulationReaderInterface::class);
        $query->expects(self::once())->method('fetchByIds')->with([5])->willReturn([$snapshot]);

        $access = $this->createMock(HospitalAccessInterface::class);
        $access->method('accessibleHospitalIds')->willReturn([5]);

        $resolver = new DataQualityPopulationResolver($query, new HospitalCohortResolver(), $access);

        $filter = new StatisticsFilter(StatisticsFilterScope::MyHospitals, null, null, StatisticsFilterPeriod::All);
        $user = $this->createMock(\App\User\Domain\Entity\User::class);

        self::assertSame([$snapshot], $resolver->resolve($filter, $user));
    }

    public function testResolvesCohortScope(): void
    {
        $cohortKey = HospitalCohortKey::tryFrom('urban_full');
        self::assertInstanceOf(HospitalCohortKey::class, $cohortKey);

        $query = $this->createMock(DataQualityHospitalPopulationReaderInterface::class);
        $query->expects(self::once())->method('fetchByCohort')->willReturn([]);

        $resolver = new DataQualityPopulationResolver(
            $query,
            new HospitalCohortResolver(),
            $this->createMock(HospitalAccessInterface::class),
        );

        $filter = new StatisticsFilter(
            StatisticsFilterScope::HospitalCohort,
            null,
            $cohortKey,
            StatisticsFilterPeriod::All,
        );

        self::assertSame([], $resolver->resolve($filter, null));
    }
}
