<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Application\Overview;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\Overview\OverviewDefaultPeriodResolver;
use App\Statistics\Application\StatisticsPeriod;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class OverviewDefaultPeriodResolverIntegrationTest extends KernelTestCase
{
    use Factories;

    public function testResolvesAllWhenSevenMonthsHaveDataInRollingWindow(): void
    {
        self::bootKernel();
        $this->seedAllocationsAcrossMonths(7);

        $resolver = self::getContainer()->get(OverviewDefaultPeriodResolver::class);
        $context = new StatisticsContext(
            null,
            new StatisticsFilter(
                StatisticsFilterScope::Public,
                null,
                null,
                StatisticsFilterPeriod::AllTime,
            ),
        );

        self::assertSame(StatisticsFilterPeriod::All, $resolver->resolveDefaultPeriod($context));
    }

    public function testResolvesAllTimeWhenOnlyThreeMonthsHaveData(): void
    {
        self::bootKernel();
        $this->seedAllocationsAcrossMonths(3);

        $resolver = self::getContainer()->get(OverviewDefaultPeriodResolver::class);
        $context = new StatisticsContext(
            null,
            new StatisticsFilter(
                StatisticsFilterScope::Public,
                null,
                null,
                StatisticsFilterPeriod::AllTime,
            ),
        );

        self::assertSame(StatisticsFilterPeriod::AllTime, $resolver->resolveDefaultPeriod($context));
    }

    private function seedAllocationsAcrossMonths(int $monthCount): void
    {
        $user = UserFactory::createOne(['username' => 'default-period-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'DefaultPeriodState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'DefaultPeriodDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'DefaultPeriodHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'DefaultPeriodSpec']);
        DepartmentFactory::createOne(['name' => 'DefaultPeriodDept']);
        AssignmentFactory::createOne(['name' => 'DefaultPeriodAssign']);
        IndicationRawFactory::createOne(['name' => 'DefaultPeriodRaw', 'code' => 912_370]);
        $indicationNormalized = IndicationNormalizedFactory::createOne(['name' => 'DefaultPeriodNorm']);

        $import = ImportFactory::createOne(['name' => 'DefaultPeriodImport', 'hospital' => $hospital, 'createdBy' => $user]);
        $start = StatisticsPeriod::overviewPeriodStart();

        for ($offset = 0; $offset < $monthCount; ++$offset) {
            $createdAt = $start->modify(sprintf('+%d months', $offset))->modify('+10 days');
            AllocationFactory::createOne([
                'import' => $import,
                'hospital' => $hospital,
                'state' => $state,
                'dispatchArea' => $dispatchArea,
                'gender' => AllocationGender::MALE,
                'urgency' => AllocationUrgency::EMERGENCY,
                'indicationNormalized' => $indicationNormalized,
                'createdAt' => $createdAt,
                'arrivalAt' => $createdAt->modify('+20 minutes'),
            ]);
        }

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());
    }
}
