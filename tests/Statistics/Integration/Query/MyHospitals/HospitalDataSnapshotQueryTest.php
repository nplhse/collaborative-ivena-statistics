<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query\MyHospitals;

use App\Allocation\Application\DTO\MyHospitals\CoveredPeriod;
use App\Allocation\Application\DTO\MyHospitals\ImportIssue;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Domain\ValueObject\ImportDataCoverage;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Infrastructure\Query\MyHospitals\HospitalDataSnapshotQuery;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class HospitalDataSnapshotQueryTest extends KernelTestCase
{
    use Factories;

    public function testSnapshotCountsOnlyRelevantAllocationImports(): void
    {
        self::bootKernel();
        $owner = UserFactory::createOne();
        $colleague = UserFactory::createOne();
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        SpecialityFactory::createOne();
        DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        IndicationRawFactory::createOne();
        $hospital = HospitalFactory::createOne(['owner' => $owner, 'createdBy' => $owner]);
        $empty = HospitalFactory::createOne(['owner' => $owner, 'createdBy' => $owner]);

        // Completed; its export period ends at midnight, so 1 February is not covered.
        $completed = $this->import($hospital, $owner, ImportStatus::COMPLETED, '2026-02-05 10:00:00');
        $completed->setExportStartsAt(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $completed->setExportEndsAt(new \DateTimeImmutable('2026-02-01 00:00:00'));
        $this->allocation($hospital, $completed, '2026-01-03 08:00:00');
        $this->allocation($hospital, $completed, '2026-01-20 12:00:00');

        // Partial with rejects and no export period: coverage falls back to the days with records (1 to 3 February).
        $partial = $this->import($hospital, $colleague, ImportStatus::PARTIAL, '2026-03-10 10:00:00');
        $partial->setRowsRejected(4);
        $partial->applyDataCoverage(new ImportDataCoverage(
            2,
            new \DateTimeImmutable('2026-02-01 09:00:00'),
            new \DateTimeImmutable('2026-02-03 18:00:00'),
            ['2026-02-01' => 1, '2026-02-03' => 1],
        ));
        $this->allocation($hospital, $partial, '2026-02-01 09:00:00');
        $this->allocation($hospital, $partial, '2026-02-03 18:00:00');

        // Failed with projection rows: they must not count.
        $failed = $this->import($hospital, $owner, ImportStatus::FAILED, '2026-04-01 10:00:00');
        $this->allocation($hospital, $failed, '2026-03-15 10:00:00');

        $cancelled = $this->import($hospital, $owner, ImportStatus::CANCELLED, '2026-04-02 10:00:00');
        $this->import($hospital, $owner, ImportStatus::PENDING, '2026-04-03 10:00:00');
        $this->import($hospital, $owner, ImportStatus::COMPLETED, '2026-04-04 10:00:00', ImportType::CLOSURE);

        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $rebuilder = self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class);
        foreach ([$completed, $partial, $failed] as $import) {
            $rebuilder->rebuildForImport((int) $import->getId());
        }

        $hospitalId = (int) $hospital->getId();
        $snapshots = self::getContainer()->get(HospitalDataSnapshotQuery::class)->read([$hospitalId, (int) $empty->getId()]);

        $snapshot = $snapshots[$hospitalId];
        self::assertSame(4, $snapshot->allocationCount);
        self::assertSame(['2026-01' => 2, '2026-02' => 2], $snapshot->monthlyVolumes);
        self::assertSame('2026-01-03 08:00:00', $snapshot->firstAllocationAt?->format('Y-m-d H:i:s'));
        self::assertSame('2026-02-03 18:00:00', $snapshot->lastAllocationAt?->format('Y-m-d H:i:s'));
        self::assertSame(
            ['2026-01-01..2026-01-31', '2026-02-01..2026-02-03'],
            array_map(static fn (CoveredPeriod $p): string => $p->firstDay->format('Y-m-d').'..'.$p->lastDay->format('Y-m-d'), $snapshot->coveredPeriods),
        );
        self::assertSame(2, $snapshot->relevantImports);
        self::assertSame(1, $snapshot->partialImports);
        self::assertSame(1, $snapshot->failedImports);
        self::assertSame(1, $snapshot->cancelledImports);
        self::assertSame(1, $snapshot->pendingImports);
        self::assertSame('2026-03-10 10:00:00', $snapshot->lastUploadAt?->format('Y-m-d H:i:s'));
        self::assertSame(1, $snapshot->uploaders[(int) $owner->getId()]->importCount);
        self::assertSame('2026-02-05', $snapshot->uploaders[(int) $owner->getId()]->lastUploadAt->format('Y-m-d'));
        self::assertSame(1, $snapshot->uploaders[(int) $colleague->getId()]->importCount);
        self::assertSame(
            [
                'rejects #'.$partial->getId().' 4',
                'day_gaps #'.$partial->getId().' 1',
                'failed #'.$failed->getId().' 0',
                'cancelled #'.$cancelled->getId().' 0',
            ],
            array_map(static fn (ImportIssue $i): string => $i->kind->value.' #'.$i->importId.' '.$i->amount, $snapshot->importIssues),
        );

        $emptySnapshot = $snapshots[(int) $empty->getId()];
        self::assertSame(0, $emptySnapshot->allocationCount);
        self::assertSame([], $emptySnapshot->monthlyVolumes);
        self::assertSame([], $emptySnapshot->coveredPeriods);
        self::assertNull($emptySnapshot->lastUploadAt);
    }

    public function testNoHospitalsReadNothing(): void
    {
        self::bootKernel();

        self::assertSame([], self::getContainer()->get(HospitalDataSnapshotQuery::class)->read([]));
    }

    private function import(Hospital $hospital, User $uploader, ImportStatus $status, string $createdAt, ImportType $type = ImportType::ALLOCATION): Import
    {
        return ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $uploader,
            'status' => $status,
            'type' => $type,
            'createdAt' => new \DateTimeImmutable($createdAt),
        ]);
    }

    private function allocation(Hospital $hospital, Import $import, string $createdAt): void
    {
        AllocationFactory::createOne([
            'hospital' => $hospital,
            'import' => $import,
            'createdAt' => new \DateTimeImmutable($createdAt),
            'arrivalAt' => new \DateTimeImmutable($createdAt)->modify('+20 minutes'),
        ]);
    }
}
