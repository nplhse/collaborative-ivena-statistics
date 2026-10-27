<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Query;

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
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\Infrastructure\Query\DoctrineImportCoverageReader;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
#[CoversClass(DoctrineImportCoverageReader::class)]
final class DoctrineImportCoverageReaderTest extends KernelTestCase
{
    use Factories;

    #[Test]
    public function countsAllocationsPerCreationDay(): void
    {
        self::bootKernel();
        $seed = $this->seed();

        $coverage = $this->reader()->allocationCoverage($seed['allocationImportId']);

        self::assertSame(4, $coverage->records);
        self::assertSame('2026-03-01 00:20:00', $coverage->firstAt?->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-04 23:40:00', $coverage->lastAt?->format('Y-m-d H:i:s'));
        self::assertSame(['2026-03-01' => 2, '2026-03-04' => 2], $coverage->dailyCounts);
        self::assertCount(1, $coverage->gaps());
    }

    #[Test]
    public function countsClosureIntervalsActivePerDay(): void
    {
        self::bootKernel();
        $seed = $this->seed();

        $coverage = $this->reader()->closureCoverage($seed['closureImportId']);

        self::assertSame(3, $coverage->records);
        self::assertSame('2026-03-04 10:00:00', $coverage->firstAt?->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-09 00:00:00', $coverage->lastAt?->format('Y-m-d H:i:s'));
        self::assertSame(
            ['2026-03-04' => 1, '2026-03-05' => 2, '2026-03-08' => 1],
            $coverage->dailyCounts,
            'An interval ending exactly at midnight does not cover the next day.',
        );
    }

    #[Test]
    public function importsWithoutRowsHaveEmptyCoverage(): void
    {
        self::bootKernel();
        $seed = $this->seed();

        self::assertTrue($this->reader()->allocationCoverage($seed['closureImportId'])->isEmpty());
        self::assertTrue($this->reader()->closureCoverage($seed['allocationImportId'])->isEmpty());
    }

    private function reader(): DoctrineImportCoverageReader
    {
        return self::getContainer()->get(DoctrineImportCoverageReader::class);
    }

    /**
     * @return array{allocationImportId: int, closureImportId: int}
     */
    private function seed(): array
    {
        $user = UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne(['state' => $state, 'dispatchArea' => $dispatch, 'owner' => $user, 'createdBy' => $user]);
        $speciality = SpecialityFactory::createOne();
        $department = DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        IndicationRawFactory::createOne(['code' => random_int(900, 999)]);
        IndicationNormalizedFactory::createOne();
        $allocationImport = ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'type' => ImportType::ALLOCATION,
            'status' => ImportStatus::COMPLETED,
        ]);
        $closureImport = ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'type' => ImportType::CLOSURE,
            'status' => ImportStatus::COMPLETED,
        ]);
        foreach (['2026-03-01 00:20:00', '2026-03-01 23:59:59', '2026-03-04 00:00:00', '2026-03-04 23:40:00'] as $createdAt) {
            AllocationFactory::createOne([
                'import' => $allocationImport,
                'hospital' => $hospital,
                'state' => $state,
                'dispatchArea' => $dispatch,
                'speciality' => $speciality,
                'department' => $department,
                'createdAt' => new \DateTimeImmutable($createdAt),
                'arrivalAt' => new \DateTimeImmutable($createdAt)->modify('+20 minutes'),
                'urgency' => AllocationUrgency::EMERGENCY,
            ]);
        }
        $connection = self::getContainer()->get(Connection::class);
        $intervals = [
            ['2026-03-04 10:00:00', '2026-03-06 00:00:00'],
            ['2026-03-05 22:00:00', '2026-03-05 23:00:00'],
            ['2026-03-08 23:00:00', '2026-03-09 00:00:00'],
        ];
        foreach ($intervals as $index => [$startsAt, $endsAt]) {
            $connection->insert('closure_interval', [
                'hospital_id' => $hospital->getId(),
                'import_id' => $closureImport->getId(),
                'speciality_id' => $speciality->getId(),
                'department_id' => $department->getId(),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'care_level' => 'emergency',
                'reason' => 'no_bed_capacity',
                'facility_kind' => 'clinic',
                'closure_unit' => 'coverage unit',
                'source_group_id' => 'coverage-'.$index,
                'source_recorded_at' => $startsAt,
                'source_changed_at' => $startsAt,
            ]);
        }

        return [
            'allocationImportId' => (int) $allocationImport->getId(),
            'closureImportId' => (int) $closureImport->getId(),
        ];
    }
}
