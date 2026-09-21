<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Application;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\StatisticsSourceDataProbe;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class StatisticsSourceDataProbeTest extends KernelTestCase
{
    use Factories;

    public function testSourceDataIgnoresTheSelectedPeriod(): void
    {
        self::bootKernel();
        $probe = self::getContainer()->get(StatisticsSourceDataProbe::class);
        self::assertInstanceOf(StatisticsSourceDataProbe::class, $probe);

        $filter = new StatisticsFilter(
            StatisticsFilterScope::Public,
            null,
            null,
            StatisticsFilterPeriod::Month,
            referenceYear: 1999,
            referenceMonth: 1,
        );

        self::assertFalse($probe->hasSourceData(null, $filter));

        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement(<<<'SQL'
            INSERT INTO allocation_stats_projection (
                id, import_id, hospital_id, state_id, dispatch_area_id,
                speciality_id, department_id, assignment_id,
                created_at, arrival_at,
                created_year, created_quarter, created_month, created_week, created_day, created_weekday, created_hour,
                day_time_bucket_code, shift_bucket_code, transport_time_minutes, urgency_code
            ) VALUES (
                1, 1, 7, 1, 3,
                1, 1, 1,
                '2024-03-01 08:00:00', '2024-03-01 08:30:00',
                2024, 1, 3, 9, 1, 5, 8,
                1, 1, 30, 1
            )
            SQL);

        self::assertTrue($probe->hasSourceData(null, $filter));
    }

    public function testCanImportFollowsHospitalImportPermission(): void
    {
        self::bootKernel();
        $probe = self::getContainer()->get(StatisticsSourceDataProbe::class);
        self::assertInstanceOf(StatisticsSourceDataProbe::class, $probe);

        $participant = UserFactory::createOne([
            'username' => 'probe-participant-'.bin2hex(random_bytes(3)),
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        self::assertFalse($probe->canImport(null));
        self::assertFalse($probe->canImport($participant));

        $owner = UserFactory::createOne([
            'username' => 'probe-owner-'.bin2hex(random_bytes(3)),
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $state = StateFactory::createOne(['name' => 'ProbeState']);
        $dispatch = DispatchAreaFactory::createOne(['name' => 'ProbeDispatch', 'state' => $state]);
        HospitalFactory::createOne([
            'name' => 'Probe Hospital',
            'owner' => $owner,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        self::assertTrue($probe->canImport($owner));
    }
}
