<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\MessageHandler;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Application\Event\ImportCompleted;
use App\Import\Application\Event\ImportFailed;
use App\Import\Application\Message\ImportAllocationsMessage;
use App\Import\Application\MessageHandler\ImportAllocationsMessageHandler;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\User\Domain\Factory\UserFactory;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class ImportAllocationsMessageHandlerFailureTest extends ImportAllocationsMessageHandlerTestCase
{
    public function testInvokeWithUnknownImportIdDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();

        $this->handler->__invoke(new ImportAllocationsMessage(2_147_483_647));
    }

    public function testInvokeWithMissingCsvFileMarksImportFailed(): void
    {
        $owner = UserFactory::createOne(['username' => 'import-missing']);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['name' => 'MissingCsv', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'MissingCsv KH',
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        $userRef = $this->em->getReference(\App\User\Domain\Entity\User::class, $owner->getId());
        $hospitalRef = $this->em->getReference(\App\Allocation\Domain\Entity\Hospital::class, $hospital->getId());

        $missingPath = sys_get_temp_dir().'/ivena-import-missing-'.bin2hex(random_bytes(8)).'.csv';

        $import = new Import()
            ->setName('Missing file IT')
            ->setHospital($hospitalRef)
            ->setCreatedBy($userRef)
            ->setType(ImportType::ALLOCATION)
            ->setStatus(ImportStatus::PENDING)
            ->setFilePath($missingPath)
            ->setFileExtension('csv')
            ->setFileMimeType('text/csv')
            ->setFileSize(0)
            ->setRunCount(0)
            ->setRunTime(0)
            ->setRowCount(0);

        $this->em->persist($import);
        $this->em->flush();

        $id = (int) $import->getId();
        self::assertGreaterThan(0, $id);

        $failedIds = [];
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $dispatcher->addListener(ImportFailed::class, function (object $event) use (&$failedIds): void {
            if ($event instanceof ImportFailed) {
                $failedIds[] = $event->importId;
            }
        });

        $this->handler->__invoke(new ImportAllocationsMessage($id));

        self::assertSame([$id], $failedIds);

        $fresh = $this->imports->find($id);
        self::assertNotNull($fresh);
        self::assertSame(ImportStatus::FAILED, $fresh->getStatus());
    }

    public function testDispatchImportOutcomeDispatchesImportCompletedForFinalImportStatus(): void
    {
        $import = $this->createPersistedImport(ImportStatus::PARTIAL, rowCount: 3, rowsPassed: 2, rowsRejected: 1);

        $dispatchedIds = [];
        $failedIds = [];
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $dispatcher->addListener(ImportCompleted::class, function (object $event) use (&$dispatchedIds): void {
            if ($event instanceof ImportCompleted) {
                $dispatchedIds[] = $event->importId;
            }
        });
        $dispatcher->addListener(ImportFailed::class, function (object $event) use (&$failedIds): void {
            if ($event instanceof ImportFailed) {
                $failedIds[] = $event->importId;
            }
        });

        $reflection = new \ReflectionMethod(ImportAllocationsMessageHandler::class, 'dispatchImportOutcome');
        $reflection->invoke($this->handler, (int) $import->getId());

        self::assertSame([], $failedIds);
        self::assertSame([(int) $import->getId()], $dispatchedIds);
    }

    public function testDispatchImportOutcomeDispatchesImportFailedForFailedImportStatus(): void
    {
        $import = $this->createPersistedImport(ImportStatus::FAILED);

        $dispatchedIds = [];
        $failedIds = [];
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $dispatcher->addListener(ImportCompleted::class, function (object $event) use (&$dispatchedIds): void {
            if ($event instanceof ImportCompleted) {
                $dispatchedIds[] = $event->importId;
            }
        });
        $dispatcher->addListener(ImportFailed::class, function (object $event) use (&$failedIds): void {
            if ($event instanceof ImportFailed) {
                $failedIds[] = $event->importId;
            }
        });

        $reflection = new \ReflectionMethod(ImportAllocationsMessageHandler::class, 'dispatchImportOutcome');
        $reflection->invoke($this->handler, (int) $import->getId(), 'CSV not found');

        self::assertSame([], $dispatchedIds);
        self::assertSame([(int) $import->getId()], $failedIds);
    }
}
