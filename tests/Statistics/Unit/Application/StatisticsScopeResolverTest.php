<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application;

use App\Statistics\Application\Cohort\HospitalCohortType;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\StatisticsScopeResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StatisticsScopeResolverTest extends KernelTestCase
{
    public function testPublicScopeReturnsUnscopedCriteria(): void
    {
        self::bootKernel();
        $resolver = self::getContainer()->get(StatisticsScopeResolver::class);

        $criteria = $resolver->resolveCriteria(new StatisticsContext(
            null,
            new StatisticsFilter(StatisticsFilterScope::Public, null, null, StatisticsFilterPeriod::All),
        ));

        self::assertNull($criteria->hospitalIds);
    }

    public function testHospitalScopeUsesSingleHospitalId(): void
    {
        self::bootKernel();
        $resolver = self::getContainer()->get(StatisticsScopeResolver::class);

        $criteria = $resolver->resolveCriteria(new StatisticsContext(
            null,
            new StatisticsFilter(StatisticsFilterScope::Hospital, 12, null, StatisticsFilterPeriod::All),
        ));

        self::assertSame([12], $criteria->hospitalIds);
    }

    public function testInvalidStateScopeFallsBackToPublicCriteria(): void
    {
        self::bootKernel();
        $resolver = self::getContainer()->get(StatisticsScopeResolver::class);

        $criteria = $resolver->resolveCriteria(new StatisticsContext(
            null,
            new StatisticsFilter(
                StatisticsFilterScope::State,
                null,
                null,
                StatisticsFilterPeriod::All,
                stateId: 999999,
            ),
        ));

        self::assertNull($criteria->hospitalIds);
    }

    public function testHospitalCohortScopeIncludesCohortMetadata(): void
    {
        self::bootKernel();
        $resolver = self::getContainer()->get(StatisticsScopeResolver::class);

        $criteria = $resolver->resolveCriteria(new StatisticsContext(
            null,
            new StatisticsFilter(
                StatisticsFilterScope::HospitalCohort,
                null,
                HospitalCohortType::UrbanBasic,
                StatisticsFilterPeriod::All,
            ),
        ));

        self::assertSame(HospitalCohortType::UrbanBasic, $criteria->cohortType);
        self::assertNotEmpty($criteria->locationCodes);
        self::assertNotEmpty($criteria->tierCodes);
    }
}
