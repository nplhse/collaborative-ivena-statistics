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

        $rows = $this->buildMemberRows($this->groupSubject($this->seedMembers([
            ['Charlie', 101, 1],
            ['Bravo', 102, 1],
            ['Alpha', 103, 1],
            ['Delta', 104, 2],
        ])));

        self::assertSame(
            [['Delta (104)', 2], ['Alpha (103)', 1], ['Bravo (102)', 1], ['Charlie (101)', 1]],
            array_map(static fn (array $row): array => [$row['label'], $row['total']], $rows),
        );
        self::assertSame([40.0, 20.0, 20.0, 20.0], array_column($rows, 'shareWithinGroup'), '5 allocations in the group');
    }

    /**
     * The catalog does not enforce unique names or codes. Two members named "Echo (105)" with one
     * allocation each tie on total and label, so the indication id decides: the older Echo first.
     * Alpha (also 1 allocation) sorts before both by label although it was created last.
     *
     * The compare picker takes its input order from the subject, so the ids are passed in the wrong
     * order on purpose: a sort without the id tie-breaker would keep the second Echo first.
     */
    public function testComparePickerOrdersEqualLabelsById(): void
    {
        self::bootKernel();

        [$firstEcho, $secondEcho, $alpha] = $this->seedMembers([
            ['Echo', 105, 1],
            ['Echo', 105, 1],
            ['Alpha', 103, 1],
        ]);
        $subject = $this->groupSubject([$secondEcho, $alpha, $firstEcho]);
        $service = self::getContainer()->get(IndicationGroupMemberCompareService::class);

        $rows = $service->buildComparePickerRows($subject, $this->buildMemberRows($subject));

        self::assertSame([$alpha, $firstEcho, $secondEcho], array_column($rows, 'indicationId'));
        self::assertSame(['Alpha (103)', 'Echo (105)', 'Echo (105)'], array_column($rows, 'label'));
        self::assertSame([1, 1, 1], array_column($rows, 'total'));
    }

    /**
     * @param list<int> $indicationIds
     */
    private function groupSubject(array $indicationIds): IndicationSubject
    {
        return new IndicationSubject(IndicationSubjectType::Group, 1, 'Order Group', $indicationIds);
    }

    /**
     * @return list<array{indicationId: int, label: string, total: int, withPhysicianShare: float, resusShare: float, urgencyEmergencyShare: float, shareWithinGroup: float}>
     */
    private function buildMemberRows(IndicationSubject $subject): array
    {
        return self::getContainer()->get(IndicationGroupMemberCompareService::class)->buildMemberRows(
            $subject,
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(new \DateTimeImmutable('2026-05-01'), new \DateTimeImmutable('2026-06-01')),
        );
    }

    /**
     * Creates the members in the given order, each with its number of allocations in May 2026.
     *
     * @param list<array{string, int, int}> $members name, code, allocations
     *
     * @return list<int> indication ids in the given order
     */
    private function seedMembers(array $members): array
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
        $day = 1;
        foreach ($members as [$name, $code, $count]) {
            $indication = IndicationNormalizedFactory::createOne(['name' => $name, 'code' => $code]);
            $ids[] = (int) $indication->getId();

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
