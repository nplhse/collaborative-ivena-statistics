<?php

declare(strict_types=1);

namespace App\Tests\Kpi\Functional\Command;

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
use App\Kpi\UI\Console\Command\KpiAggregateCommand;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class KpiAggregateCommandTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testCommandAggregatesImportsForGivenDate(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);

        $seed = $this->seedReferenceGraph();
        $user = $seed['user'];
        $hospital = $seed['hospital'];
        $day = new \DateTimeImmutable('2026-05-10 14:00:00', new \DateTimeZone('Europe/Berlin'));

        ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'createdAt' => $day,
            'status' => ImportStatus::COMPLETED,
            'rowCount' => 100,
            'rowsPassed' => 95,
            'rowsRejected' => 5,
        ]);
        ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'createdAt' => $day->modify('+2 hours'),
            'status' => ImportStatus::FAILED,
            'rowCount' => 0,
            'rowsRejected' => 0,
        ]);

        $command = self::getContainer()->get(KpiAggregateCommand::class);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--date' => '2026-05-10']);
        $tester->assertCommandIsSuccessful();
        self::assertSame(0, $exitCode);
        self::assertStringContainsString('KPI aggregation finished:', $tester->getDisplay());
        self::assertStringContainsString('(2026-05-10)', $tester->getDisplay());
        self::assertStringNotContainsString('Day ', $tester->getDisplay());

        $global = $connection->fetchAssociative(
            'SELECT imports_count, records_processed, records_rejected, successful_imports_count, failed_imports_count
             FROM kpi_daily WHERE date = :date AND hospital_id IS NULL',
            ['date' => '2026-05-10'],
        );
        self::assertIsArray($global);
        self::assertSame(2, (int) $global['imports_count']);
        self::assertSame(100, (int) $global['records_processed']);
        self::assertSame(5, (int) $global['records_rejected']);
        self::assertSame(1, (int) $global['successful_imports_count']);
        self::assertSame(1, (int) $global['failed_imports_count']);

        $hospitalRow = $connection->fetchAssociative(
            'SELECT imports_count FROM kpi_daily WHERE date = :date AND hospital_id = :hospitalId',
            ['date' => '2026-05-10', 'hospitalId' => $hospital->getId()],
        );
        self::assertIsArray($hospitalRow);
        self::assertSame(2, (int) $hospitalRow['imports_count']);

        $secondRun = $tester->execute(['--date' => '2026-05-10']);
        $tester->assertCommandIsSuccessful();
        self::assertSame(0, $secondRun);

        $rowCount = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM kpi_daily WHERE date = :date',
            ['date' => '2026-05-10'],
        );
        self::assertSame(2, $rowCount, 'Idempotent re-run must not duplicate rows.');
    }

    public function testCommandRejectsInvalidDate(): void
    {
        self::bootKernel();
        $command = self::getContainer()->get(KpiAggregateCommand::class);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--date' => 'not-a-date']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Invalid --date', $tester->getDisplay());
    }

    /**
     * @return array{user: object, hospital: object}
     */
    private function seedReferenceGraph(): array
    {
        $user = UserFactory::createOne(['username' => 'kpi-aggregate-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'KpiAggregateState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'KpiAggregateDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'KpiAggregateHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ]);

        SpecialityFactory::createOne(['name' => 'KpiAggregateSpeciality']);
        DepartmentFactory::createOne(['name' => 'KpiAggregateDepartment']);
        AssignmentFactory::createOne(['name' => 'KpiAggregateAssignment']);
        OccasionFactory::createOne(['name' => 'KpiAggregateOccasion']);
        SecondaryTransportFactory::createOne(['name' => 'KpiAggregateSecondary']);
        InfectionFactory::createOne(['name' => 'KpiAggregateInfection']);
        IndicationRawFactory::createOne(['name' => 'KpiAggregateRaw', 'code' => 800003]);
        IndicationNormalizedFactory::createOne(['name' => 'KpiAggregateNormalized']);

        return [
            'user' => $user,
            'hospital' => $hospital,
        ];
    }
}
