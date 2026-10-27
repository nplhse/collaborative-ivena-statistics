<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Application\ReferenceCatalog;

use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogChecksum;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackage;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackageReader;
use App\Allocation\Application\ReferenceCatalog\Restore\CatalogMaintenanceGuard;
use App\Allocation\Application\ReferenceCatalog\Restore\ReferenceCatalogDependencyInspector;
use App\Allocation\Application\ReferenceCatalog\Restore\ReferenceCatalogRestoreBlockedException;
use App\Allocation\Application\ReferenceCatalog\Restore\ReferenceCatalogRestorer;
use App\Allocation\Application\ReferenceCatalog\Restore\RestoreBlocker;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Tests\Support\ReferenceCatalog\ReferenceCatalogPackageData as Data;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ReferenceCatalogRestoreTest extends KernelTestCase
{
    use Factories;

    public function testRestoreReplacesTheCatalogAndKeepsThePackageIds(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne();
        $this->staleCatalog();

        $this->restorer()->restore($this->package(Data::complete()), $user);

        self::assertSame(['Bayern', 'Hessen'], $this->column('SELECT name FROM state ORDER BY name'));
        self::assertSame([], $this->column("SELECT name FROM department WHERE name = 'Veraltete Abteilung'"), 'stale synthetic rows are gone');
        self::assertSame([Data::HOSPITAL], $this->column('SELECT public_id FROM hospital'));
        self::assertSame(['Allgem. Innere Medizin'], $this->column('SELECT name FROM department_alias'));
    }

    public function testAHospitalOwnerBlocksTheRestore(): void
    {
        self::bootKernel();
        HospitalFactory::createOne(['owner' => UserFactory::createOne()]);

        self::assertSame(['hospital'], $this->blockedTables());
    }

    public function testAccessGrantsBlockTheRestoreInsteadOfBeingDeletedByTheCascade(): void
    {
        self::bootKernel();
        $saarland = StateFactory::createOne(['name' => 'Saarland']);
        HospitalAccessGrantFactory::createOne(['hospital' => HospitalFactory::new(['owner' => null, 'state' => $saarland, 'dispatchArea' => DispatchAreaFactory::new(['state' => $saarland])])]);

        self::assertContains('hospital_access_grant', $this->blockedTables());
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM hospital_access_grant'));
    }

    public function testOperationalDataBlocksTheRestore(): void
    {
        self::bootKernel();
        UserFactory::createOne();
        ImportFactory::createOne(['hospital' => HospitalFactory::createOne()]);

        $blocked = $this->blockedTables();

        self::assertContains('import', $blocked);
        self::assertContains('hospital', $blocked, 'factory hospitals have an owner');
    }

    public function testAProjectionWithoutForeignKeyBlocksTheRestore(): void
    {
        self::bootKernel();
        $this->connection()->executeStatement("INSERT INTO closure_rebuild_request (hospital_id, kind, requested_at) VALUES (999, 'hospital', NOW())");

        $blockers = $this->inspector()->blockers();

        self::assertSame(['closure_rebuild_request'], array_map(static fn (RestoreBlocker $blocker): string => $blocker->table, $blockers));
        self::assertSame('projection', $blockers[0]->kind);
    }

    public function testAFailureLeavesTheOldCatalogInPlace(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne();
        $this->staleCatalog();
        $this->em()->getEventManager()->addEventListener([Events::prePersist], new class {
            public function prePersist(PrePersistEventArgs $args): void
            {
                if ($args->getObject() instanceof Hospital) {
                    throw new \RuntimeException('forced failure');
                }
            }
        });

        try {
            $this->restorer()->restore($this->package(Data::complete()), $user);
            self::fail('The restore should have failed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('forced failure', $exception->getMessage());
        }

        self::assertSame(['Bayern', 'Saarland'], $this->column('SELECT name FROM state ORDER BY name'));
        self::assertSame(['Veraltete Abteilung'], $this->column('SELECT name FROM department'));
    }

    public function testABlockedRestoreChangesNothing(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne();
        $saarland = StateFactory::createOne(['name' => 'Saarland']);
        HospitalFactory::createOne(['owner' => $user, 'state' => $saarland, 'dispatchArea' => DispatchAreaFactory::createOne(['state' => $saarland])]);

        try {
            $this->restorer()->restore($this->package(Data::complete()), $user);
            self::fail('The restore should have been blocked.');
        } catch (ReferenceCatalogRestoreBlockedException $exception) {
            self::assertSame('hospital', $exception->blockers[0]->table);
        }

        self::assertSame(['Saarland'], $this->column('SELECT name FROM state'));
    }

    public function testAnIncompletePackageCannotBeRestored(): void
    {
        self::bootKernel();
        $full = Data::complete();
        $partial = ['meta' => ['types' => ['state'], 'complete' => false] + $full['meta'], 'states' => $full['states']];

        $this->expectException(\InvalidArgumentException::class);

        $this->restorer()->restore($this->package($partial), UserFactory::createOne());
    }

    public function testTheGuardHoldsOneLockAndReportsBusyQueues(): void
    {
        self::bootKernel();
        /** @var CatalogMaintenanceGuard $guard */
        $guard = self::getContainer()->get(CatalogMaintenanceGuard::class);

        $lock = $guard->acquire();
        self::assertNotNull($lock);
        self::assertNull($guard->acquire(), 'a second restore or reset must wait');
        $lock->release();
        $again = $guard->acquire();
        self::assertNotNull($again, 'the lock is free again after release');
        $again->release();

        self::assertSame([], $guard->busyQueues());
        $insert = "INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at, delivered_at) VALUES ('{}', '{}', :queue, NOW(), NOW(), :delivered)";
        $this->connection()->executeStatement($insert, ['queue' => 'high', 'delivered' => null]);
        $this->connection()->executeStatement($insert, ['queue' => 'low', 'delivered' => '2026-10-27 10:00:00']);
        $this->connection()->executeStatement($insert, ['queue' => 'failed', 'delivered' => null]);

        self::assertSame(['high' => 1, 'low' => 1], $guard->busyQueues(), 'waiting and in-progress messages count, failed ones do not');
    }

    private function staleCatalog(): void
    {
        $saarland = StateFactory::createOne(['name' => 'Saarland']);
        StateFactory::createOne(['name' => 'Bayern']);
        DepartmentFactory::createOne(['name' => 'Veraltete Abteilung']);
        HospitalFactory::createOne([
            'owner' => null,
            'state' => $saarland,
            'dispatchArea' => DispatchAreaFactory::createOne(['state' => $saarland]),
        ]);
    }

    /**
     * @return list<string>
     */
    private function blockedTables(): array
    {
        return array_map(static fn (RestoreBlocker $blocker): string => $blocker->table, $this->inspector()->blockers());
    }

    /**
     * @param array<string, mixed> $data
     */
    private function package(array $data): ReferenceCatalogPackage
    {
        /** @var DenormalizerInterface $denormalizer */
        $denormalizer = self::getContainer()->get(DenormalizerInterface::class);
        /** @var ReferenceCatalogChecksum $checksum */
        $checksum = self::getContainer()->get(ReferenceCatalogChecksum::class);
        /** @var ReferenceCatalogPackageReader $reader */
        $reader = self::getContainer()->get(ReferenceCatalogPackageReader::class);

        $result = $reader->readJson(json_encode(Data::withChecksum(Data::withCounts($data), $denormalizer, $checksum), \JSON_THROW_ON_ERROR));
        self::assertSame([], $result->violations);
        self::assertNotNull($result->package);

        return $result->package;
    }

    /**
     * @return list<string>
     */
    private function column(string $sql): array
    {
        /** @var list<string> $values */
        $values = array_map(strval(...), $this->connection()->fetchFirstColumn($sql));

        return $values;
    }

    private function restorer(): ReferenceCatalogRestorer
    {
        /** @var ReferenceCatalogRestorer $restorer */
        $restorer = self::getContainer()->get(ReferenceCatalogRestorer::class);

        return $restorer;
    }

    private function inspector(): ReferenceCatalogDependencyInspector
    {
        /** @var ReferenceCatalogDependencyInspector $inspector */
        $inspector = self::getContainer()->get(ReferenceCatalogDependencyInspector::class);

        return $inspector;
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }
}
