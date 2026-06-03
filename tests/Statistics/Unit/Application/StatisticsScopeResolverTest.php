<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application;

use App\Statistics\Application\Cohort\HospitalCohortKey;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\StatisticsScopeResolver;
use App\User\Domain\Factory\UserFactory;
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
                new HospitalCohortKey(\App\Allocation\Domain\Enum\HospitalLocation::URBAN, \App\Allocation\Domain\Enum\HospitalTier::BASIC),
                StatisticsFilterPeriod::All,
            ),
        ));

        self::assertInstanceOf(HospitalCohortKey::class, $criteria->cohortType);
        self::assertSame('urban_basic', $criteria->cohortType->value());
        self::assertNotEmpty($criteria->locationCodes);
        self::assertNotEmpty($criteria->tierCodes);
    }

    public function testMyHospitalsScopeWithoutAccessFallsBackToPublicCriteria(): void
    {
        self::bootKernel();
        $resolver = self::getContainer()->get(StatisticsScopeResolver::class);
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']]);

        $criteria = $resolver->resolveCriteria(new StatisticsContext(
            $user,
            new StatisticsFilter(StatisticsFilterScope::MyHospitals, null, null, StatisticsFilterPeriod::All),
        ));

        self::assertNull($criteria->hospitalIds);
    }
}
