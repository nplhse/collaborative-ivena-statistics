<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Command;

use App\Allocation\Domain\Enum\AllocationUrgency;
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
use App\Statistics\UI\Console\Command\RebuildClosureVolumeProjectionCommand;
use App\Tests\Statistics\Support\RebuildsClosureAnalysis;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class RebuildClosureVolumeProjectionCommandTest extends KernelTestCase
{
    use Factories;
    use RebuildsClosureAnalysis;

    #[Test]
    public function commandRebuildsClosureVolumeProjection(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $seed = $this->seedClosureGraph($connection);
        $this->rebuildClosureAnalysis();
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($seed['importId']);

        /** @var RebuildClosureVolumeProjectionCommand $command */
        $command = self::getContainer()->get(RebuildClosureVolumeProjectionCommand::class);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertSame(0, $exitCode);

        self::assertGreaterThan(
            0,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM closure_volume_hour'),
        );
        self::assertStringContainsString('Closure volume projection rebuilt', $tester->getDisplay());
    }

    /**
     * @return array{importId: int}
     */
    private function seedClosureGraph(Connection $connection): array
    {
        $user = UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'state' => $state,
            'dispatchArea' => $dispatch,
            'owner' => $user,
            'createdBy' => $user,
        ]);
        $speciality = SpecialityFactory::createOne();
        $department = DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        IndicationRawFactory::createOne(['code' => random_int(900, 999)]);
        IndicationNormalizedFactory::createOne();
        $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user]);
        $importId = (int) $import->getId();

        $connection->insert('closure_interval', [
            'hospital_id' => $hospital->getId(),
            'import_id' => $importId,
            'speciality_id' => $speciality->getId(),
            'department_id' => $department->getId(),
            'starts_at' => '2026-06-02 10:00:00',
            'ends_at' => '2026-06-02 11:00:00',
            'care_level' => 'emergency',
            'reason' => 'no_bed_capacity',
            'facility_kind' => 'clinic',
            'closure_unit' => 'command volume unit',
            'source_group_id' => 'command-volume-group',
            'source_recorded_at' => '2026-06-02 09:00:00',
            'source_changed_at' => '2026-06-02 09:00:00',
        ]);

        foreach (['2026-05-05', '2026-05-12', '2026-05-19', '2026-05-26'] as $day) {
            AllocationFactory::createOne([
                'import' => $import,
                'hospital' => $hospital,
                'state' => $state,
                'dispatchArea' => $dispatch,
                'speciality' => $speciality,
                'department' => $department,
                'createdAt' => new \DateTimeImmutable($day.' 10:10:00'),
                'arrivalAt' => new \DateTimeImmutable($day.' 10:30:00'),
                'urgency' => AllocationUrgency::EMERGENCY,
            ]);
        }
        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatch,
            'speciality' => $speciality,
            'department' => $department,
            'createdAt' => new \DateTimeImmutable('2026-06-02 10:15:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-06-02 10:35:00'),
            'urgency' => AllocationUrgency::EMERGENCY,
        ]);

        return ['importId' => $importId];
    }
}
