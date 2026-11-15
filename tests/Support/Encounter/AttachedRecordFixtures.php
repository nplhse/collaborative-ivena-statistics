<?php

declare(strict_types=1);

namespace App\Tests\Support\Encounter;

use App\Allocation\Domain\Entity\Allocation;
use App\Encounter\Domain\Entity\Encounter;
use App\Encounter\Domain\Entity\EncounterMatch;
use App\Encounter\Domain\Enum\AnchorSource;
use App\Encounter\Domain\Enum\MatchStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A record table of an attaching schema without a bundle (ADR 037): `test_attached_record` references
 * `encounter` with ON DELETE RESTRICT and its import with ON DELETE CASCADE, like a plugin's table. Created
 * inside the test's transaction (DAMA), so it disappears with it.
 *
 * Needs a booted kernel.
 */
trait AttachedRecordFixtures
{
    private function createAttachedRecordTable(): void
    {
        $this->attachedConnection()->executeStatement(
            'CREATE TABLE test_attached_record (id SERIAL PRIMARY KEY, encounter_id INT REFERENCES encounter (id) ON DELETE RESTRICT, import_id INT NOT NULL REFERENCES import (id) ON DELETE CASCADE)',
        );
    }

    private function attachRecord(int $encounterId, int $importId): void
    {
        $this->attachedConnection()->executeStatement('INSERT INTO test_attached_record (encounter_id, import_id) VALUES (?, ?)', [$encounterId, $importId]);
    }

    private function attachedRecordCount(): int
    {
        return (int) $this->attachedConnection()->fetchOne('SELECT COUNT(*) FROM test_attached_record');
    }

    /**
     * A record of $importId gets attached to a visit while the import's deletion is already running: when
     * its link is removed (EncounterMatchDataRemover, after EncounterAttachedRecordsGuard), so only the
     * database can still block the deletion.
     */
    private function attachRecordWhenTheLinkIsRemoved(int $importId): void
    {
        $this->attachedConnection()->executeStatement(sprintf(
            'CREATE FUNCTION test_attach_on_unlink() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN INSERT INTO test_attached_record (encounter_id, import_id) VALUES (OLD.encounter_id, %d); RETURN OLD; END $$',
            $importId,
        ));
        $this->attachedConnection()->executeStatement('CREATE TRIGGER test_attach_on_unlink BEFORE DELETE ON encounter_match FOR EACH ROW EXECUTE FUNCTION test_attach_on_unlink()');
    }

    /**
     * Any other database error while the import is deleted.
     */
    private function failWhenTheLinkIsRemoved(): void
    {
        $this->attachedConnection()->executeStatement("CREATE FUNCTION test_fail_on_unlink() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'test failure'; END \$\$");
        $this->attachedConnection()->executeStatement('CREATE TRIGGER test_fail_on_unlink BEFORE DELETE ON encounter_match FOR EACH ROW EXECUTE FUNCTION test_fail_on_unlink()');
    }

    /**
     * Effective (auto-accepted) link of the visit with the allocation. Create the allocation
     * (MatchingDataFixtures::allocationAt()) before an import run clears the entity manager.
     */
    private function linkVisit(int $allocationId, int $encounterId): void
    {
        $em = $this->attachedEntityManager();
        $encounter = $em->find(Encounter::class, $encounterId);
        self::assertInstanceOf(Encounter::class, $encounter);
        $allocation = $em->find(Allocation::class, $allocationId);
        self::assertInstanceOf(Allocation::class, $allocation);

        $em->persist(new EncounterMatch($allocation, $encounter, MatchStatus::AutoAccepted, AnchorSource::Assessment, 7, new \DateTimeImmutable('2026-10-12 09:00'), 0.9697, 9, ['time' => 4, 'sex' => 2, 'age' => 2, 'arrival_route' => 1], 1, '1.0.0'));
        $em->flush();
    }

    /**
     * @return list<array{allocation_id: int, encounter_id: int, status: string}>
     */
    private function links(): array
    {
        /** @var list<array{allocation_id: int, encounter_id: int, status: string}> $rows */
        $rows = $this->attachedConnection()->fetchAllAssociative('SELECT allocation_id, encounter_id, status FROM encounter_match ORDER BY id');

        return $rows;
    }

    private function attachedConnection(): Connection
    {
        return $this->attachedEntityManager()->getConnection();
    }

    private function attachedEntityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
