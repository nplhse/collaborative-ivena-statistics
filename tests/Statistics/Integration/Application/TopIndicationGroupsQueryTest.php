<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Application;

use App\Allocation\Domain\Entity\IndicationGroup;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationGroupFactory;
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
use App\Statistics\Application\TopIndicationGroupsQuery;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class TopIndicationGroupsQueryTest extends KernelTestCase
{
    use Factories;

    public function testFetchReturnsGroupsOrderedByAllocationCount(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'top-groups-query-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'TopGroupsQueryState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'TopGroupsQueryDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'TopGroupsQueryHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ]);

        SpecialityFactory::createOne(['name' => 'TopGroupsQuerySpec']);
        DepartmentFactory::createOne(['name' => 'TopGroupsQueryDept']);
        AssignmentFactory::createOne(['name' => 'TopGroupsQueryAssign']);
        IndicationRawFactory::createOne(['name' => 'TopGroupsQueryRaw', 'code' => 912_365]);

        $indicationA = IndicationNormalizedFactory::createOne(['name' => 'Top Groups Indication A']);
        $indicationB = IndicationNormalizedFactory::createOne(['name' => 'Top Groups Indication B']);
        $groupA = IndicationGroupFactory::createOne(['name' => 'Leading Group', 'createdBy' => $user]);
        $groupB = IndicationGroupFactory::createOne(['name' => 'Smaller Group', 'createdBy' => $user]);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $groupAEntity = $entityManager->find(IndicationGroup::class, $groupA->getId());
        $groupBEntity = $entityManager->find(IndicationGroup::class, $groupB->getId());
        self::assertNotNull($groupAEntity);
        self::assertNotNull($groupBEntity);
        $groupAEntity->addIndication($indicationA->_real());
        $groupBEntity->addIndication($indicationB->_real());
        $entityManager->flush();

        $import = ImportFactory::createOne(['name' => 'TopGroupsQueryImport', 'hospital' => $hospital, 'createdBy' => $user]);

        AllocationFactory::createMany(2, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationNormalized' => $indicationA,
            'createdAt' => new \DateTimeImmutable('2026-04-01 12:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-01 12:20:00'),
        ]);

        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationNormalized' => $indicationB,
            'createdAt' => new \DateTimeImmutable('2026-04-02 12:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-02 12:20:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $result = self::getContainer()->get(TopIndicationGroupsQuery::class)->fetch(
            new StatisticsContext(
                $user->_real(),
                new StatisticsFilter(StatisticsFilterScope::Hospital, $hospital->getId(), null, StatisticsFilterPeriod::All),
            ),
            10,
        );

        self::assertSame(3, $result['totalAllocations']);
        self::assertCount(2, $result['rows']);
        self::assertSame('Leading Group', $result['rows'][0]['label']);
        self::assertSame(2, $result['rows'][0]['count']);
        self::assertSame((int) $groupA->getId(), $result['rows'][0]['groupId']);
        self::assertSame('Smaller Group', $result['rows'][1]['label']);
        self::assertSame(1, $result['rows'][1]['count']);
    }
}
