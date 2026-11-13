<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Application\IndicationGroup;

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
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\IndicationDashboard\IndicationSubject;
use App\Statistics\Application\IndicationDashboard\IndicationSubjectType;
use App\Statistics\Application\IndicationGroup\IndicationGroupMemberCompareService;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class IndicationGroupMemberCompareServiceTest extends KernelTestCase
{
    use Factories;

    /**
     * Delta has 2 allocations, Charlie, Bravo and Alpha 1 each. The members are created in the order
     * Charlie, Bravo, Alpha, Delta, so neither id nor insert order matches the expected order:
     * Delta (highest total), then the tied members by label: Alpha, Bravo, Charlie.
     */
    public function testMemberRowsOrderTiedTotalsByLabel(): void
    {
        self::bootKernel();

        $members = $this->seedMembers(['Charlie' => 1, 'Bravo' => 1, 'Alpha' => 1, 'Delta' => 2]);
        $service = self::getContainer()->get(IndicationGroupMemberCompareService::class);

        $rows = $service->buildMemberRows(
            new IndicationSubject(IndicationSubjectType::Group, 1, 'Order Group', array_values($members)),
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(new \DateTimeImmutable('2026-05-01'), new \DateTimeImmutable('2026-06-01')),
        );

        self::assertSame(
            [['Delta (104)', 2], ['Alpha (103)', 1], ['Bravo (102)', 1], ['Charlie (101)', 1]],
            array_map(static fn (array $row): array => [$row['label'], $row['total']], $rows),
        );
        self::assertSame([40.0, 20.0, 20.0, 20.0], array_column($rows, 'shareWithinGroup'), '5 allocations in the group');
    }

    /**
     * @param array<string, int> $allocationsByName
     *
     * @return array<string, int> indication id by name
     */
    private function seedMembers(array $allocationsByName): array
    {
        $user = UserFactory::createOne(['username' => 'member-order-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'MemberOrderState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'MemberOrderDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'MemberOrderHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);
        SpecialityFactory::createOne(['name' => 'MemberOrderSpec']);
        DepartmentFactory::createOne(['name' => 'MemberOrderDept']);
        AssignmentFactory::createOne(['name' => 'MemberOrderAssign']);
        IndicationRawFactory::createOne(['name' => 'MemberOrderRaw', 'code' => 912_401]);

        $import = ImportFactory::createOne(['name' => 'MemberOrderImport', 'hospital' => $hospital, 'createdBy' => $user]);

        $ids = [];
        $code = 101;
        $day = 1;
        foreach ($allocationsByName as $name => $count) {
            $indication = IndicationNormalizedFactory::createOne(['name' => $name, 'code' => $code++]);
            $ids[$name] = (int) $indication->getId();

            for ($i = 0; $i < $count; ++$i) {
                AllocationFactory::createOne([
                    'import' => $import,
                    'hospital' => $hospital,
                    'state' => $state,
                    'dispatchArea' => $dispatchArea,
                    'gender' => AllocationGender::MALE,
                    'urgency' => AllocationUrgency::EMERGENCY,
                    'indicationNormalized' => $indication,
                    'createdAt' => new \DateTimeImmutable(\sprintf('2026-05-%02d 10:00:00', $day)),
                    'arrivalAt' => new \DateTimeImmutable(\sprintf('2026-05-%02d 10:20:00', $day)),
                ]);
                ++$day;
            }
        }

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        return $ids;
    }
}
