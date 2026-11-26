<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Infrastructure;

use App\Allocation\Application\DTO\MyHospitals\HospitalDataSnapshot;
use App\Allocation\Application\Hospital\MyHospitals\CachedHospitalDataSnapshotReader;
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
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The cached My Hospitals snapshot follows import and projection changes without a page having to clear it.
 * Warm messages run synchronously in the test environment, so every read below comes from the cache.
 */
#[ResetDatabase]
final class HospitalDataSnapshotInvalidationTest extends KernelTestCase
{
    use Factories;

    private User $owner;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->owner = UserFactory::createOne();
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        SpecialityFactory::createOne();
        DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        IndicationRawFactory::createOne();
    }

    public function testImportChangesRefreshTheCachedSnapshot(): void
    {
        $hospital = $this->hospital();
        $import = $this->import($hospital, ImportStatus::COMPLETED);
        self::assertSame(1, $this->snapshot($hospital)->relevantImports);

        $this->import($hospital, ImportStatus::FAILED);
        self::assertSame(1, $this->snapshot($hospital)->failedImports, 'A new import counts at once.');

        $import->setStatus(ImportStatus::CANCELLED);
        $this->em()->flush();
        $snapshot = $this->snapshot($hospital);
        self::assertSame(0, $snapshot->relevantImports, 'The end of an import run counts at once.');
        self::assertSame(1, $snapshot->cancelledImports);

        $this->em()->remove($import);
        $this->em()->flush();
        self::assertSame(0, $this->snapshot($hospital)->cancelledImports, 'A deleted import counts at once.');
    }

    public function testProjectionRebuildRefreshesTheMonthlyVolumes(): void
    {
        $hospital = $this->hospital();
        $import = $this->import($hospital, ImportStatus::COMPLETED);
        AllocationFactory::createOne([
            'hospital' => $hospital,
            'import' => $import,
            'createdAt' => new \DateTimeImmutable('2026-01-03 08:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-01-03 08:20:00'),
        ]);
        self::assertSame([], $this->snapshot($hospital)->monthlyVolumes, 'Without projection rows there is no volume yet.');

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport((int) $import->getId());
        self::assertSame(['2026-01' => 1], $this->snapshot($hospital)->monthlyVolumes);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->deleteForImport((int) $import->getId());
        self::assertSame([], $this->snapshot($hospital)->monthlyVolumes);
    }

    public function testMovingAnImportRefreshesBothHospitals(): void
    {
        $from = $this->hospital();
        $to = $this->hospital();
        $import = $this->import($from, ImportStatus::COMPLETED);
        self::assertSame(1, $this->snapshot($from)->relevantImports);
        self::assertSame(0, $this->snapshot($to)->relevantImports);

        $import->setHospital($to);
        $this->em()->flush();

        self::assertSame(0, $this->snapshot($from)->relevantImports);
        self::assertSame(1, $this->snapshot($to)->relevantImports);
    }

    public function testChangesTheSnapshotDoesNotReadKeepTheCachedEntry(): void
    {
        $hospital = $this->hospital();
        $import = $this->import($hospital, ImportStatus::COMPLETED);
        $closure = $this->import($hospital, ImportStatus::COMPLETED, ImportType::CLOSURE);
        self::assertSame(1, $this->snapshot($hospital)->relevantImports);

        // Changed behind Doctrine's back: only an invalidation would reveal it.
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE import SET status = :status WHERE id = :id',
            ['status' => ImportStatus::FAILED->value, 'id' => $import->getId()],
        );

        $import->setRowsPassed(10)->setRunTime(1500);
        $closure->setStatus(ImportStatus::FAILED);
        $this->em()->flush();
        self::assertSame(1, $this->snapshot($hospital)->relevantImports, 'Progress and closure imports keep the entry.');

        $import->setRowsRejected(2);
        $this->em()->flush();
        self::assertSame(0, $this->snapshot($hospital)->relevantImports, 'A field the snapshot reads drops it.');
    }

    private function snapshot(Hospital $hospital): HospitalDataSnapshot
    {
        $id = (int) $hospital->getId();

        return self::getContainer()->get(CachedHospitalDataSnapshotReader::class)->read([$id])[$id];
    }

    private function hospital(): Hospital
    {
        return HospitalFactory::createOne(['owner' => $this->owner, 'createdBy' => $this->owner]);
    }

    private function import(Hospital $hospital, ImportStatus $status, ImportType $type = ImportType::ALLOCATION): Import
    {
        return ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $this->owner,
            'status' => $status,
            'type' => $type,
            'createdAt' => new \DateTimeImmutable('2026-02-05 10:00:00'),
        ]);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
