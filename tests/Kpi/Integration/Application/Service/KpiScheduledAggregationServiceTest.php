<?php

declare(strict_types=1);

namespace App\Tests\Kpi\Integration\Application\Service;

use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Kpi\Application\Service\KpiScheduledAggregationService;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class KpiScheduledAggregationServiceTest extends KernelTestCase
{
    use Factories;

    public function testRunAggregatesYesterdayAndToday(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $service = self::getContainer()->get(KpiScheduledAggregationService::class);

        $seed = $this->seedReferenceGraph();
        $user = $seed['user'];
        $hospital = $seed['hospital'];
        $tz = new \DateTimeZone('Europe/Berlin');
        $yesterday = new \DateTimeImmutable('yesterday', $tz);
        $today = new \DateTimeImmutable('today', $tz);

        ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'createdAt' => $yesterday->setTime(10, 0),
            'status' => ImportStatus::COMPLETED,
            'rowCount' => 50,
            'rowsPassed' => 48,
            'rowsRejected' => 2,
        ]);
        ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'createdAt' => $today->setTime(11, 0),
            'status' => ImportStatus::COMPLETED,
            'rowCount' => 30,
            'rowsPassed' => 30,
            'rowsRejected' => 0,
        ]);

        $result = $service->run();

        self::assertSame(2, $result->daysProcessed);
        self::assertSame(4, $result->totalRows);
        self::assertSame(2, $result->daysWithData);
        self::assertSame([
            $yesterday->format('Y-m-d'),
            $today->format('Y-m-d'),
        ], $result->dates);

        $yesterdayGlobal = $connection->fetchAssociative(
            'SELECT imports_count, records_processed FROM kpi_daily WHERE date = :date AND hospital_id IS NULL',
            ['date' => $yesterday->format('Y-m-d')],
        );
        self::assertIsArray($yesterdayGlobal);
        self::assertSame(1, (int) $yesterdayGlobal['imports_count']);
        self::assertSame(50, (int) $yesterdayGlobal['records_processed']);

        $todayGlobal = $connection->fetchAssociative(
            'SELECT imports_count, records_processed FROM kpi_daily WHERE date = :date AND hospital_id IS NULL',
            ['date' => $today->format('Y-m-d')],
        );
        self::assertIsArray($todayGlobal);
        self::assertSame(1, (int) $todayGlobal['imports_count']);
        self::assertSame(30, (int) $todayGlobal['records_processed']);
    }

    /**
     * @return array{user: object, hospital: object}
     */
    private function seedReferenceGraph(): array
    {
        $user = UserFactory::createOne(['username' => 'kpi-scheduled-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'KpiScheduledState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'KpiScheduledDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'KpiScheduledHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ]);

        SpecialityFactory::createOne(['name' => 'KpiScheduledSpeciality']);
        DepartmentFactory::createOne(['name' => 'KpiScheduledDepartment']);
        AssignmentFactory::createOne(['name' => 'KpiScheduledAssignment']);
        OccasionFactory::createOne(['name' => 'KpiScheduledOccasion']);
        SecondaryTransportFactory::createOne(['name' => 'KpiScheduledSecondary']);
        InfectionFactory::createOne(['name' => 'KpiScheduledInfection']);
        IndicationRawFactory::createOne(['name' => 'KpiScheduledRaw', 'code' => 800004]);
        IndicationNormalizedFactory::createOne(['name' => 'KpiScheduledNormalized']);

        return [
            'user' => $user,
            'hospital' => $hospital,
        ];
    }
}
