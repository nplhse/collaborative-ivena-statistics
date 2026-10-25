<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\MessageHandler;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Application\Message\DeleteImportsMessage;
use App\Import\Application\MessageHandler\DeleteImportsMessageHandler;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Message\RebuildAllocationStatsProjection;
use App\Statistics\Application\MessageHandler\RebuildAllocationStatsProjectionHandler;
use App\Statistics\Infrastructure\Entity\ProjectionHospitalDimension;
use App\Statistics\Infrastructure\Query\Overview\OverviewMaterializedViewsInstaller;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class DeleteImportsMessageHandlerTest extends DatabaseKernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testDeletesAllowedImportsSkipsTheOthersAndSendsOneSummary(): void
    {
        $owner = $this->participant();
        $hospital = $this->hospital($owner);
        $first = $this->import($owner, $hospital);
        $second = $this->import($owner, $hospital);
        $running = $this->import($owner, $hospital, ImportStatus::RUNNING);
        $foreignOwner = $this->participant();
        $foreign = $this->import($foreignOwner, $this->hospital($foreignOwner));
        $ids = [(int) $first->getId(), (int) $running->getId(), (int) $foreign->getId(), (int) $second->getId(), 2_000_000_000];
        $this->actAs($owner);

        $message = new DeleteImportsMessage('0199e000-0000-7000-8000-000000000001', (int) $owner->getId(), $ids);
        $this->handler()($message);

        self::assertSame(
            [(int) $running->getId(), (int) $foreign->getId()],
            array_map(intval(...), $this->connection()->fetchFirstColumn('SELECT id FROM import WHERE id IN (?) ORDER BY id', [$ids], [\Doctrine\DBAL\ArrayParameterType::INTEGER])),
            'the running and the foreign import stay',
        );

        $row = $this->connection()->fetchAssociative('SELECT type, parameters, action_route, priority FROM notification WHERE recipient_id = ?', [$owner->getId()]);
        self::assertIsArray($row);
        self::assertSame('import.bulk_deleted', $row['type']);
        // The unknown id counts as deleted: it is gone, for example after an earlier attempt of this message.
        self::assertSame(['total' => 5, 'deleted' => 3, 'skipped' => 2, 'failed' => 0], json_decode((string) $row['parameters'], true));
        self::assertSame('app_import_index', $row['action_route']);
        self::assertSame('normal', $row['priority']);
    }

    public function testARetryDoesNotSendASecondSummary(): void
    {
        $owner = $this->participant();
        $import = $this->import($owner, $this->hospital($owner));
        $this->actAs($owner);
        $message = new DeleteImportsMessage('0199e000-0000-7000-8000-000000000002', (int) $owner->getId(), [(int) $import->getId()]);

        $this->handler()($message);
        $this->handler()($message);

        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM notification WHERE recipient_id = ?', [$owner->getId()]));
    }

    public function testRefreshesTheOverviewOnceWhenAHospitalLeavesTheProjection(): void
    {
        $owner = $this->participant();
        $hospital = $this->hospital($owner);
        $import = $this->importWithProjection($owner, $hospital);
        self::assertInstanceOf(ProjectionHospitalDimension::class, $this->entityManager()->find(ProjectionHospitalDimension::class, $hospital->getId()));
        $this->actAs($owner);

        $this->handler()(new DeleteImportsMessage('0199e000-0000-7000-8000-000000000003', (int) $owner->getId(), [(int) $import->getId()]));

        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->find(ProjectionHospitalDimension::class, $hospital->getId()), 'the deferred refresh ran at the end');
    }

    public function testAFailingImportDoesNotStopTheOthers(): void
    {
        $owner = $this->participant();
        $hospital = $this->hospital($owner);
        $failing = $this->import($owner, $hospital);
        $deleted = $this->import($owner, $hospital);
        $this->actAs($owner);
        $connection = $this->connection();
        // A database error while deleting one import, as a lock timeout or a constraint would cause it.
        $connection->executeStatement(\sprintf(
            "CREATE FUNCTION test_block_import_delete() RETURNS trigger AS $$ BEGIN IF OLD.id = %d THEN RAISE EXCEPTION 'blocked'; END IF; RETURN OLD; END $$ LANGUAGE plpgsql",
            (int) $failing->getId(),
        ));
        $connection->executeStatement('CREATE TRIGGER test_block_import_delete BEFORE DELETE ON import FOR EACH ROW EXECUTE FUNCTION test_block_import_delete()');

        try {
            $this->handler()(new DeleteImportsMessage('0199e000-0000-7000-8000-000000000004', (int) $owner->getId(), [(int) $failing->getId(), (int) $deleted->getId()]));
        } finally {
            $connection->executeStatement('DROP TRIGGER IF EXISTS test_block_import_delete ON import');
            $connection->executeStatement('DROP FUNCTION IF EXISTS test_block_import_delete()');
        }

        $remaining = array_map(intval(...), $connection->fetchFirstColumn('SELECT id FROM import'));
        self::assertContains((int) $failing->getId(), $remaining);
        self::assertNotContains((int) $deleted->getId(), $remaining, 'the next import is still deleted');

        $row = $connection->fetchAssociative('SELECT type, parameters, priority FROM notification WHERE recipient_id = ?', [$owner->getId()]);
        self::assertIsArray($row);
        self::assertSame('import.bulk_delete_failed', $row['type']);
        self::assertSame(['total' => 2, 'deleted' => 1, 'skipped' => 0, 'failed' => 1], json_decode((string) $row['parameters'], true));
        self::assertSame('high', $row['priority']);
    }

    private function handler(): DeleteImportsMessageHandler
    {
        return self::getContainer()->get(DeleteImportsMessageHandler::class);
    }

    /**
     * The audit middleware authenticates the initiator for the message; the test does the same.
     */
    private function actAs(User $user): void
    {
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function participant(): User
    {
        return UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
    }

    private function hospital(User $owner): Hospital
    {
        $state = StateFactory::createOne();

        return HospitalFactory::createOne([
            'owner' => $owner,
            'createdBy' => $owner,
            'state' => $state,
            'dispatchArea' => DispatchAreaFactory::createOne(['state' => $state]),
        ]);
    }

    private function import(User $creator, Hospital $hospital, ImportStatus $status = ImportStatus::COMPLETED): Import
    {
        return ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $creator,
            'type' => ImportType::ALLOCATION,
            'status' => $status,
            'filePath' => 'var/imports/_tests/never-written.csv',
        ]);
    }

    private function importWithProjection(User $owner, Hospital $hospital): Import
    {
        $import = ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $owner,
            'type' => ImportType::ALLOCATION,
            'status' => ImportStatus::COMPLETED,
            'filePath' => 'var/imports/_tests/never-written.csv',
        ]);

        SpecialityFactory::createOne(['name' => 'Bulk Delete Speciality']);
        DepartmentFactory::createOne(['name' => 'Bulk Delete Department']);
        AssignmentFactory::createOne(['name' => 'Bulk Delete Assignment']);
        OccasionFactory::createOne(['name' => 'Bulk Delete Occasion']);
        InfectionFactory::createOne(['name' => 'Bulk Delete Infection']);
        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'dispatchArea' => $hospital->getDispatchArea(),
            'state' => $hospital->getState(),
            'indicationRaw' => IndicationRawFactory::createOne(['name' => 'Bulk Delete Raw']),
            'indicationNormalized' => IndicationNormalizedFactory::createOne(['name' => 'Bulk Delete Normalized']),
        ]);

        self::getContainer()->get(OverviewMaterializedViewsInstaller::class)->ensureInstalled();
        self::getContainer()->get(RebuildAllocationStatsProjectionHandler::class)->__invoke(new RebuildAllocationStatsProjection((int) $import->getId()));

        return $import;
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
