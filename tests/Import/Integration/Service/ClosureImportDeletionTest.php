<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Service;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Application\Service\ImportDeletionService;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\ClosureRebuildRequestInterface;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;

/**
 * `closure_interval.import_id` has no ON DELETE CASCADE, so deleting a closure import has to remove its intervals
 * itself and ask for the closure analysis of its export period to be rebuilt.
 *
 * Fixture: one hospital with closure imports A (2 intervals) and B (1 interval). Deleting A leaves only B's interval.
 */
final class ClosureImportDeletionTest extends DatabaseKernelTestCase
{
    public function testDeletingAClosureImportRemovesOnlyItsIntervalsAndRequestsTheAnalysisRebuild(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne();
        $state = StateFactory::createOne();
        $hospital = HospitalFactory::createOne(['state' => $state, 'dispatchArea' => DispatchAreaFactory::createOne(['state' => $state])]);
        $speciality = SpecialityFactory::createOne();
        $department = DepartmentFactory::createOne();
        $importA = $this->closureImport($hospital, $user, '2026-03-01', '2026-04-01');
        $importB = $this->closureImport($hospital, $user, '2026-04-01', '2026-05-01');
        foreach ([[$importA, '2026-03-10 08:00'], [$importA, '2026-03-20 08:00'], [$importB, '2026-04-10 08:00']] as [$import, $startsAt]) {
            $this->insertInterval($import, $hospital->getId(), $speciality->getId(), $department->getId(), $startsAt);
        }
        $importAId = (int) $importA->getId();

        $requests = $this->createMock(ClosureRebuildRequestInterface::class);
        $requests->expects($this->once())->method('requestAnalysis')->with(
            $hospital->getId(),
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-04-01'),
        );
        $requests->expects($this->never())->method('requestVolume');
        self::getContainer()->set(ClosureRebuildRequestInterface::class, $requests);

        self::getContainer()->get(ImportDeletionService::class)->delete($importA);

        $connection = self::getContainer()->get(Connection::class);
        self::assertFalse($connection->fetchOne('SELECT 1 FROM import WHERE id = :id', ['id' => $importAId]));
        self::assertSame(
            [(int) $importB->getId()],
            array_map(intval(...), $connection->fetchFirstColumn('SELECT import_id FROM closure_interval')),
        );
    }

    private function closureImport(Hospital $hospital, User $user, string $from, string $to): Import
    {
        return ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'type' => ImportType::CLOSURE,
            'status' => ImportStatus::COMPLETED,
            'exportStartsAt' => new \DateTimeImmutable($from),
            'exportEndsAt' => new \DateTimeImmutable($to),
        ]);
    }

    private function insertInterval(Import $import, int $hospitalId, int $specialityId, int $departmentId, string $startsAt): void
    {
        $endsAt = new \DateTimeImmutable($startsAt)->modify('+2 hours')->format('Y-m-d H:i');
        self::getContainer()->get(Connection::class)->insert('closure_interval', [
            'hospital_id' => $hospitalId,
            'import_id' => $import->getId(),
            'speciality_id' => $specialityId,
            'department_id' => $departmentId,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'care_level' => 'emergency',
            'reason' => 'no_bed_capacity',
            'facility_kind' => 'clinic',
            'source_recorded_at' => $startsAt,
            'source_changed_at' => $startsAt,
        ]);
    }
}
