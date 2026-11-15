<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Infrastructure\Import;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Message\MatchEncountersMessage;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Import\Application\Contracts\ImportDeletionRefusedException;
use App\Import\Application\Service\ImportDeletionService;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use App\Tests\Support\Encounter\AttachedRecordFixtures;
use App\Tests\Support\Encounter\EdVisitFileBuilder;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use App\Tests\Support\Encounter\EncounterImportRunner;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\Tests\Support\Encounter\MatchingDataFixtures;
use App\User\Domain\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Translation\TranslatableMessage;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Deleting an import removes only its own records (ADR 037): while records of other imports are attached to
 * the visits an `ed_visit` import created, its deletion is refused and the import stays complete, whether
 * EncounterAttachedRecordsGuard refuses first or only the RESTRICT reference blocks it.
 *
 * Visits a and b of one `ed_visit` import; a is linked to an allocation. The attached record belongs to an
 * import of another type, so nothing else could remove it.
 */
#[ResetDatabase]
final class EncounterImportDeletionRefusalTest extends KernelTestCase
{
    use AttachedRecordFixtures;
    use EncounterDataFixtures;
    use EncounterImportRunner;
    use EncounterTestFixtures;
    use Factories;
    use MatchingDataFixtures;

    private Hospital $hospital;

    private User $uploader;

    private int $edImportId;

    private int $visitA;

    private int $otherImportId;

    /** @var \ArrayObject<int, object> */
    private \ArrayObject $sent;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->hospital = $this->hospital($this->user());
        $this->uploader = $this->user();
        $this->encounterGrant($this->hospital, $this->uploader, [EncounterGrantAction::Upload]);
        $allocationId = (int) $this->allocationAt($this->hospital, $this->encounterImport($this->hospital, 'allocations', ImportType::ALLOCATION), '08:30')->getId();
        $this->otherImportId = (int) $this->encounterImport($this->hospital, 'attaching records', ImportType::ALLOCATION)->getId();
        $this->edImportId = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()
            ->visit('a', '2026-10-12T08:15')
            ->visit('b', '2026-10-12T09:15')
            ->build(), 'ed visits');
        $this->visitA = $this->visitIdsOfImport($this->edImportId, 'a')['a'];
        $this->linkVisit($allocationId, $this->visitA);
        $this->createAttachedRecordTable();
        $this->sent = $this->recordSentMessages();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeEncounterFiles();
        parent::tearDown();
    }

    public function testGuardRefusesBeforeAnythingIsRemoved(): void
    {
        $this->attachRecord($this->visitA, $this->otherImportId);
        $before = $this->state();

        $refusal = $this->deleteRefused();

        self::assertSame('encounter.import.delete_refused.attached_records', $this->reasonKey($refusal));
        self::assertSame($before, $this->state());
        self::assertSame([], $this->sentMessages(), 'No remover ran, so no matcher rerun was queued.');
    }

    public function testRestrictReferenceRefusesTheSameWayAndRollsEverythingBack(): void
    {
        // Attached only once the deletion is running, after the guard: the database blocks the removal.
        $this->attachRecordWhenTheLinkIsRemoved($this->otherImportId);
        $before = $this->state();

        $refusal = $this->deleteRefused();

        self::assertSame('encounter.import.delete_refused.attached_records', $this->reasonKey($refusal));
        self::assertNotNull($refusal->getPrevious(), 'The refusal keeps the database violation.');
        self::assertSame($before, $this->state(), 'Visits, link, attached records, file and import are rolled back.');
        // The matcher rerun was queued inside the removal transaction; with the Doctrine transport of dev
        // and prod it goes with the rollback, here (sync) its writes are rolled back with it.
        self::assertSame([MatchEncountersMessage::class], $this->sentMessages());
    }

    public function testOtherDatabaseErrorsStayErrors(): void
    {
        $this->failWhenTheLinkIsRemoved();

        try {
            $this->deletion()->delete($this->freshImport());
            self::fail('The deletion should fail.');
        } catch (\Throwable $error) {
            self::assertNotInstanceOf(ImportDeletionRefusedException::class, $error);
            self::assertStringContainsString('test failure', $error->getMessage());
        }
    }

    public function testImportWithoutAttachedRecordsIsDeleted(): void
    {
        $this->attachRecord($this->visitA, $this->otherImportId);
        $this->connection()->executeStatement('DELETE FROM test_attached_record');

        $this->deletion()->delete($this->freshImport());

        self::assertNull($this->entityManager()->find(Import::class, $this->edImportId));
        self::assertSame(0, $this->encounterCount($this->edImportId));
    }

    private function deleteRefused(): ImportDeletionRefusedException
    {
        try {
            $this->deletion()->delete($this->freshImport());
        } catch (ImportDeletionRefusedException $refusal) {
            // A failed transaction closes the entity manager, as for the callers.
            $registry = self::getContainer()->get(ManagerRegistry::class);
            self::assertInstanceOf(ManagerRegistry::class, $registry);
            $registry->resetManager();

            return $refusal;
        }

        self::fail('The deletion should be refused.');
    }

    /**
     * @return array{import: bool, visits: int, links: list<array{allocation_id: int, encounter_id: int, status: string}>, attached: int, file: bool}
     */
    private function state(): array
    {
        $this->entityManager()->clear();
        $import = $this->entityManager()->find(Import::class, $this->edImportId);

        return [
            'import' => $import instanceof Import,
            'visits' => $this->encounterCount($this->edImportId),
            'links' => $this->links(),
            'attached' => $this->attachedRecordCount(),
            'file' => $import instanceof Import && is_file($this->projectDir().'/'.$import->getFilePath()),
        ];
    }

    private function freshImport(): Import
    {
        $this->entityManager()->clear();
        $import = $this->entityManager()->find(Import::class, $this->edImportId);
        self::assertInstanceOf(Import::class, $import);

        return $import;
    }

    private function deletion(): ImportDeletionService
    {
        $service = self::getContainer()->get(ImportDeletionService::class);
        self::assertInstanceOf(ImportDeletionService::class, $service);

        return $service;
    }

    private function reasonKey(ImportDeletionRefusedException $refusal): string
    {
        self::assertInstanceOf(TranslatableMessage::class, $refusal->reason);

        return $refusal->reason->getMessage();
    }

    /**
     * @return \ArrayObject<int, object>
     */
    private function recordSentMessages(): \ArrayObject
    {
        /** @var \ArrayObject<int, object> $sent */
        $sent = new \ArrayObject();
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(SendMessageToTransportsEvent::class, static function (SendMessageToTransportsEvent $event) use ($sent): void {
            $sent[] = $event->getEnvelope()->getMessage();
        });

        return $sent;
    }

    /**
     * @return list<class-string>
     */
    private function sentMessages(): array
    {
        return array_values(array_unique(array_map(static fn (object $message): string => $message::class, $this->sent->getArrayCopy())));
    }
}
