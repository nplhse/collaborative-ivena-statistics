<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Infrastructure\KeyChange;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\EncounterKeyChangerInterface;
use App\Encounter\Domain\Entity\EncounterKeyState;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Encounter\Domain\Enum\EncounterKeyChangeOption;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use App\Tests\Support\Encounter\AttachedRecordFixtures;
use App\Tests\Support\Encounter\EdVisitFileBuilder;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use App\Tests\Support\Encounter\EncounterFileBuilder;
use App\Tests\Support\Encounter\EncounterImportRunner;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\Tests\Support\Encounter\Probe\ProbeDimension;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Key change with the option "delete" while records of attaching schemas hang on the old visits (ADR 037):
 * imports of attaching schemas go first, so the `ed_visit` imports are not blocked; an import that is still
 * refused stays complete with its old generation and the key change ends.
 */
#[ResetDatabase]
final class EncounterKeyChangeAttachedRecordsTest extends KernelTestCase
{
    use AttachedRecordFixtures;
    use EncounterDataFixtures;
    use EncounterImportRunner;
    use EncounterTestFixtures;
    use Factories;

    private User $owner;

    private Hospital $hospital;

    private User $uploader;

    private int $edImportId;

    private int $visitA;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->owner = $this->user();
        $this->hospital = $this->hospital($this->owner);
        $this->uploader = $this->user();
        $this->encounterGrant($this->hospital, $this->uploader, [EncounterGrantAction::Upload]);
        $this->edImportId = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()->visit('a', '2026-10-12T08:15')->build(), 'ed visits');
        $this->visitA = $this->visitIdsOfImport($this->edImportId, 'a')['a'];
        $this->createAttachedRecordTable();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeEncounterFiles();
        parent::tearDown();
    }

    public function testImportsOfAttachingSchemasAreDeletedFirst(): void
    {
        // The probe import (an attaching schema) is newer than the ed_visit import, so ascending ids alone
        // would delete the ed_visit import first and be refused.
        $probeImportId = $this->uploadEncounterFile($this->hospital, $this->uploader, EncounterFileBuilder::for(ProbeDimension::SCHEMA, ProbeDimension::header(), ProbeDimension::FINGERPRINT_RULE)
            ->row(['record_fingerprint' => hash('sha256', 'probe|a'), 'case_key' => EncounterFileBuilder::caseKey('a'), 'recorded_at' => '2026-10-12T12:00'])
            ->build(), 'probe', schema: ProbeDimension::SCHEMA);
        $this->attachRecord($this->visitA, $probeImportId);

        $this->changeKey();

        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM import WHERE hospital_id = ?', [$this->hospital->getId()]));
        self::assertSame(0, $this->attachedRecordCount());
        self::assertFalse($this->keyChangeInProgress());
    }

    public function testTheEdImportIsDeletedWhileTheAttachedRecordKeepsItsEncounter(): void
    {
        // The record belongs to an import the key change does not delete. Since #1300 the ED visit is a record of
        // its own: deleting its import removes the visit, the encounter stays with the attached record.
        $other = $this->encounterImport($this->hospital, 'attaching records', ImportType::ALLOCATION);
        $this->attachRecord($this->visitA, (int) $other->getId());

        $this->changeKey();

        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->find(Import::class, $this->edImportId));
        self::assertSame(0, $this->encounterCount($this->edImportId));
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM encounter WHERE id = ?', [$this->visitA]));
        self::assertSame(1, $this->attachedRecordCount());
        self::assertFalse($this->keyChangeInProgress());
    }

    private function changeKey(): void
    {
        $changer = self::getContainer()->get(EncounterKeyChangerInterface::class);
        self::assertInstanceOf(EncounterKeyChangerInterface::class, $changer);
        $hospital = $this->entityManager()->find(Hospital::class, (int) $this->hospital->getId());
        self::assertInstanceOf(Hospital::class, $hospital);

        $changer->change($hospital, EncounterKeyChangeOption::Delete, $this->entityManager()->find(User::class, (int) $this->owner->getId()));
    }

    private function keyChangeInProgress(): bool
    {
        $this->entityManager()->clear();
        $state = $this->entityManager()->getRepository(EncounterKeyState::class)->findOneBy(['hospital' => $this->hospital->getId()]);
        self::assertInstanceOf(EncounterKeyState::class, $state);

        return $state->isKeyChangeInProgress();
    }
}
