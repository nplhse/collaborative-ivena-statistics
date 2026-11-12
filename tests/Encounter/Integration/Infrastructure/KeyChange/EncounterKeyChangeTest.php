<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Infrastructure\KeyChange;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\EncounterKeyChangerInterface;
use App\Encounter\Application\KeyChange\EncounterKeyChangeInProgressException;
use App\Encounter\Application\Message\DeleteEncounterDataAfterKeyChangeMessage;
use App\Encounter\Application\Message\ImportEncountersMessage;
use App\Encounter\Domain\Entity\EncounterKeyState;
use App\Encounter\Domain\Enum\EncounterFileRejection;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Encounter\Domain\Enum\EncounterKeyChangeOption;
use App\Encounter\Infrastructure\KeyChange\DeleteEncounterDataAfterKeyChangeMessageHandler;
use App\Encounter\Infrastructure\KeyChange\EncounterKeyChanger;
use App\Import\Application\Service\ImportRunLifecycle;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Entity\ImportRun;
use App\Import\Domain\Enum\ImportRunReasonCode;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Domain\Enum\ImportStatus;
use App\Shared\Infrastructure\Audit\Entity\AuditEntry;
use App\Tests\Support\Encounter\EdVisitFileBuilder;
use App\Tests\Support\Encounter\EncounterImportRunner;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Key change of a hospital (ADR 026, #1050) with small synthetic files. Files carry the key check value
 * EdVisitFileBuilder::KEY_CHECK ("old key") unless stated otherwise; the same visit label is the same
 * visit, but its fingerprint only matches within one key generation.
 *
 * The deletion message is handled synchronously in the test environment, so a change with "delete"
 * has finished when change() returns.
 */
#[ResetDatabase]
final class EncounterKeyChangeTest extends KernelTestCase
{
    use EncounterImportRunner;
    use EncounterTestFixtures;
    use Factories;

    private const string NEW_KEY_CHECK = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private Hospital $hospital;

    private User $owner;

    private User $uploader;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->owner = $this->user();
        $this->hospital = $this->hospital($this->owner);
        $this->uploader = $this->user();
        $this->encounterGrant($this->hospital, $this->uploader, [EncounterGrantAction::Upload]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeEncounterFiles();
        parent::tearDown();
    }

    public function testKeepMovesToTheNextGenerationAndLeavesTheVisitsIntact(): void
    {
        $first = $this->upload(EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15')->visit('b', '2026-10-01T09:00'));
        $visitsBefore = $this->visitsOfHospital();

        $generation = $this->changeKey(EncounterKeyChangeOption::Keep);

        self::assertSame(2, $generation);
        self::assertSame(
            ['generation' => 2, 'in_progress' => false, 'active' => null, 'retired' => [EdVisitFileBuilder::KEY_CHECK]],
            $this->keyState(),
        );
        self::assertSame($visitsBefore, $this->visitsOfHospital(), 'Visits keep their uid and generation 1.');
        self::assertSame(2, $this->encounterCount($first));
        self::assertSame(ImportStatus::COMPLETED, $this->importStatus($first));
    }

    public function testKeepWithTheSameVisitExportedAgainCountsItTwiceInDifferentGenerations(): void
    {
        $this->upload(EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15'), 'old key');
        $this->changeKey(EncounterKeyChangeOption::Keep);

        // The new key gives the same visit another fingerprint; here it keeps the label's fingerprint,
        // which still differs from generation 1 in the uniqueness key (hospital, generation, fingerprint).
        $second = $this->upload(EdVisitFileBuilder::create()->manifest('key_check', self::NEW_KEY_CHECK)->visit('a', '2026-10-01T08:15'), 'new key', keyGeneration: 2);

        self::assertSame(ImportStatus::COMPLETED, $this->importStatus($second));
        self::assertSame(1, $this->encounterImportOf($second)->getInserted(), 'Not recognised as duplicate across generations.');
        self::assertSame([1 => 1, 2 => 1], $this->visitCountByGeneration());
        self::assertSame(self::NEW_KEY_CHECK, $this->keyState()['active'], 'The first upload of generation 2 registers the new key.');
    }

    public function testDeleteRemovesEveryEncounterImportWithItsVisitsAndFiles(): void
    {
        $first = $this->upload(EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15')->visit('b', '2026-10-01T09:00'), 'first');
        $second = $this->upload(EdVisitFileBuilder::create()->visit('b', '2026-10-01T09:00')->visit('c', '2026-10-02T10:00'), 'second');
        $rejected = $this->upload(EdVisitFileBuilder::create()->manifest('key_check', self::NEW_KEY_CHECK)->visit('d', '2026-10-03T10:00'), 'other key');
        $files = [$this->storedPath($first), $this->storedPath($second), $this->storedPath($rejected)];
        $otherHospital = $this->hospital($this->user());
        $this->encounterGrant($otherHospital, $this->uploader, [EncounterGrantAction::Upload]);
        $foreign = $this->uploadEncounterFile($otherHospital, $this->uploader, EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15')->build(), 'other hospital');

        $generation = $this->changeKey(EncounterKeyChangeOption::Delete);

        self::assertSame(2, $generation);
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM encounter WHERE hospital_id = ?', [$this->hospital->getId()]));
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM import WHERE hospital_id = ?', [$this->hospital->getId()]));
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM encounter_import ei INNER JOIN import i ON i.id = ei.import_id WHERE i.hospital_id = ?', [$this->hospital->getId()]));
        foreach ($files as $file) {
            self::assertFileDoesNotExist($this->projectDir().'/'.$file);
        }
        self::assertSame(
            ['generation' => 2, 'in_progress' => false, 'active' => null, 'retired' => [EdVisitFileBuilder::KEY_CHECK]],
            $this->keyState(),
            'The worker ended the key change after the deletion.',
        );
        self::assertSame(1, $this->encounterCount($foreign), 'Other hospitals keep their data.');
    }

    public function testFileWithTheRetiredCheckValueIsRejectedAfterTheChange(): void
    {
        $this->upload(EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15'));
        $this->changeKey(EncounterKeyChangeOption::Keep);

        $importId = $this->upload(EdVisitFileBuilder::create()->visit('b', '2026-10-02T08:15'), 'old key file', keyGeneration: 2);

        self::assertSame(EncounterFileRejection::KeyCheckRetired, $this->encounterImportOf($importId)->getFileRejection());
        self::assertSame(ImportStatus::FAILED, $this->importStatus($importId));
        self::assertSame(0, $this->encounterCount($importId));
        self::assertNull($this->keyState()['active'], 'The retired value is not registered again.');
    }

    public function testImportOfTheOldGenerationQueuedBeforeTheChangeWritesNothing(): void
    {
        $importId = $this->storeEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15')->build(), 'queued');
        $queued = $this->queueRun($importId);

        $this->changeKey(EncounterKeyChangeOption::Keep);
        $this->bus()->dispatch($queued);
        $this->entityManager()->clear();

        self::assertSame(ImportStatus::FAILED, $this->importStatus($importId));
        self::assertSame(ImportRunReasonCode::KeyGenerationChanged, $this->lastRunReason($importId));
        self::assertSame(0, $this->encounterCount($importId));
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM import_reject WHERE import_id = ?', [$importId]));
        self::assertNull($this->keyState()['active'], 'The old key check value is not registered for generation 2.');
    }

    public function testChangeIsAuditedWithTheChosenOption(): void
    {
        $this->upload(EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15'));

        $this->changeKey(EncounterKeyChangeOption::Keep);

        $entries = $this->auditEntries(EncounterKeyChanger::AUDIT_INTENT);
        self::assertCount(1, $entries);
        self::assertSame('update', $entries[0]->getAction());
        self::assertSame(
            ['hospital_id' => $this->hospital->getId(), 'option' => 'keep', 'previous_generation' => 1, 'generation' => 2],
            $entries[0]->getMetadata()['intent_metadata'] ?? null,
        );
    }

    public function testDeleteIsAuditedAndTheEndOfTheChangeToo(): void
    {
        $this->upload(EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15'));

        $this->changeKey(EncounterKeyChangeOption::Delete);

        self::assertSame('delete', $this->auditEntries(EncounterKeyChanger::AUDIT_INTENT)[0]->getMetadata()['intent_metadata']['option'] ?? null);
        self::assertCount(1, $this->auditEntries(DeleteEncounterDataAfterKeyChangeMessageHandler::COMPLETED_AUDIT_INTENT));
    }

    public function testAdministratorsAreNotified(): void
    {
        $admin = $this->admin();

        $this->changeKey(EncounterKeyChangeOption::Delete);

        $rows = $this->connection()->fetchAllAssociative("SELECT parameters, action_route, required_role FROM notification WHERE recipient_id = ? AND type = 'admin.encounter_key_changed'", [$admin->getId()]);
        self::assertCount(1, $rows);
        self::assertSame(
            ['hospitalName' => $this->hospital->getName(), 'username' => $this->owner->getUsername(), 'generation' => 2, 'option' => 'delete'],
            json_decode((string) $rows[0]['parameters'], true),
        );
        self::assertSame('app_encounter_key_change', $rows[0]['action_route']);
        self::assertSame('ROLE_ADMIN', $rows[0]['required_role']);
    }

    public function testAnotherChangeCannotStartWhileOneIsInProgress(): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO encounter_key_state (hospital_id, key_generation, key_change_in_progress, created_at) VALUES (?, 2, true, '2026-11-01 10:00:00')",
            [$this->hospital->getId()],
        );

        try {
            $this->changeKey(EncounterKeyChangeOption::Keep);
            self::fail('Expected the change to be refused.');
        } catch (EncounterKeyChangeInProgressException) {
        }

        self::assertSame(2, $this->keyState()['generation']);
        self::assertTrue($this->keyState()['in_progress']);
    }

    public function testDeletionWaitsForARunningImportAndKeepsTheChangeInProgress(): void
    {
        $done = $this->upload(EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15'), 'done');
        $running = $this->upload(EdVisitFileBuilder::create()->visit('b', '2026-10-01T09:15'), 'running');
        $this->connection()->executeStatement('UPDATE import SET status = ? WHERE id = ?', [ImportStatus::RUNNING->value, $running]);
        $this->connection()->executeStatement(
            'UPDATE encounter_key_state SET key_generation = 2, key_change_in_progress = true WHERE hospital_id = ?',
            [$this->hospital->getId()],
        );

        try {
            $this->deletionHandler()(new DeleteEncounterDataAfterKeyChangeMessage((int) $this->hospital->getId(), 2));
            self::fail('Expected a retry while an import is running.');
        } catch (RecoverableMessageHandlingException) {
        }

        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM import WHERE id = ?', [$done]));
        self::assertSame(1, $this->encounterCount($running));
        self::assertTrue($this->keyState()['in_progress'], 'Uploads stay refused until everything is deleted.');

        $this->connection()->executeStatement('UPDATE import SET status = ? WHERE id = ?', [ImportStatus::FAILED->value, $running]);
        $this->deletionHandler()(new DeleteEncounterDataAfterKeyChangeMessage((int) $this->hospital->getId(), 2));

        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM import WHERE hospital_id = ?', [$this->hospital->getId()]));
        self::assertFalse($this->keyState()['in_progress']);
    }

    private function changeKey(EncounterKeyChangeOption $option): int
    {
        $changer = self::getContainer()->get(EncounterKeyChangerInterface::class);
        self::assertInstanceOf(EncounterKeyChangerInterface::class, $changer);
        $hospital = $this->entityManager()->find(Hospital::class, (int) $this->hospital->getId());
        self::assertInstanceOf(Hospital::class, $hospital);
        $actor = $this->entityManager()->find(User::class, (int) $this->owner->getId());

        $generation = $changer->change($hospital, $option, $actor);
        $this->entityManager()->clear();

        return $generation;
    }

    /**
     * Registers an upload run like the upload does, but keeps its message instead of handling it.
     */
    private function queueRun(int $importId): ImportEncountersMessage
    {
        $lifecycle = self::getContainer()->get(ImportRunLifecycle::class);
        self::assertInstanceOf(ImportRunLifecycle::class, $lifecycle);
        $message = null;
        $lifecycle->register($importId, ImportRunTrigger::Upload, (int) $this->uploader->getId(), null, static function (ImportRun $run) use ($importId, &$message): void {
            $message = new ImportEncountersMessage($importId, $run->getId()->toRfc4122());
        });
        self::assertInstanceOf(ImportEncountersMessage::class, $message);

        return $message;
    }

    private function upload(EdVisitFileBuilder $file, string $name = 'encounters', int $keyGeneration = 1): int
    {
        return $this->uploadEncounterFile($this->hospital, $this->uploader, $file->build(), $name, $keyGeneration);
    }

    /**
     * @return array{generation: int, in_progress: bool, active: string|null, retired: list<string>}
     */
    private function keyState(): array
    {
        $this->entityManager()->clear();
        $state = $this->entityManager()->getRepository(EncounterKeyState::class)->findOneBy(['hospital' => $this->hospital->getId()]);
        self::assertInstanceOf(EncounterKeyState::class, $state);

        return [
            'generation' => $state->getKeyGeneration(),
            'in_progress' => $state->isKeyChangeInProgress(),
            'active' => $state->getActiveCheckValue(),
            'retired' => $state->getRetiredCheckValues(),
        ];
    }

    /**
     * @return array<string, array{visit_uid: string, key_generation: int}> by fingerprint
     */
    private function visitsOfHospital(): array
    {
        $visits = [];
        foreach ($this->connection()->fetchAllAssociative('SELECT visit_fingerprint, visit_uid, key_generation FROM encounter WHERE hospital_id = ? ORDER BY visit_fingerprint', [$this->hospital->getId()]) as $row) {
            $visits[(string) $row['visit_fingerprint']] = ['visit_uid' => (string) $row['visit_uid'], 'key_generation' => (int) $row['key_generation']];
        }

        return $visits;
    }

    /**
     * @return array<int, int> generation => visits
     */
    private function visitCountByGeneration(): array
    {
        $counts = [];
        foreach ($this->connection()->fetchAllKeyValue('SELECT key_generation, COUNT(*) FROM encounter WHERE hospital_id = ? GROUP BY key_generation ORDER BY key_generation', [$this->hospital->getId()]) as $generation => $count) {
            $counts[(int) $generation] = (int) $count;
        }

        return $counts;
    }

    private function importStatus(int $importId): ?ImportStatus
    {
        $this->entityManager()->clear();

        return $this->entityManager()->find(Import::class, $importId)?->getStatus();
    }

    private function storedPath(int $importId): string
    {
        return (string) $this->entityManager()->find(Import::class, $importId)?->getFilePath();
    }

    private function lastRunReason(int $importId): ?ImportRunReasonCode
    {
        $run = $this->entityManager()->getRepository(ImportRun::class)->findOneBy(['import' => $importId], ['id' => 'DESC']);
        self::assertInstanceOf(ImportRun::class, $run);

        return $run->getReasonCode();
    }

    /**
     * @return list<AuditEntry>
     */
    private function auditEntries(string $intent): array
    {
        $entries = $this->entityManager()->getRepository(AuditEntry::class)->findBy(['entityClass' => EncounterKeyState::class], ['id' => 'ASC']);

        return array_values(array_filter($entries, static fn (AuditEntry $entry): bool => ($entry->getMetadata()['intent'] ?? null) === $intent));
    }

    private function bus(): MessageBusInterface
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }

    private function deletionHandler(): DeleteEncounterDataAfterKeyChangeMessageHandler
    {
        $handler = self::getContainer()->get(DeleteEncounterDataAfterKeyChangeMessageHandler::class);
        self::assertInstanceOf(DeleteEncounterDataAfterKeyChangeMessageHandler::class, $handler);

        return $handler;
    }
}
