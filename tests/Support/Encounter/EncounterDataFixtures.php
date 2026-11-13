<?php

declare(strict_types=1);

namespace App\Tests\Support\Encounter;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Encounter\Domain\Entity\Encounter;
use App\Encounter\Domain\Entity\EncounterTimes;
use App\Encounter\Domain\Enum\EncounterInsertOutcome;
use App\Encounter\Domain\Enum\Sex;
use App\Encounter\Infrastructure\Repository\EncounterRepository;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Hand-checkable encounter data: one visit per calendar day, identified by its date.
 *
 * The same date in the same hospital gives the same fingerprint, so two imports that both contain
 * "2026-10-12" contain the same visit. Needs a booted kernel and Foundry's Factories trait.
 */
trait EncounterDataFixtures
{
    private function encounterHospital(): Hospital
    {
        $state = StateFactory::createOne();

        return HospitalFactory::createOne([
            'owner' => UserFactory::createOne(),
            'createdBy' => UserFactory::createOne(),
            'state' => $state,
            'dispatchArea' => DispatchAreaFactory::createOne(['state' => $state]),
        ]);
    }

    /**
     * Owning import of directly inserted visits. Without encounter state (EncounterImport): these tests
     * do not run the import; EncounterImportRunner does.
     */
    private function encounterImport(Hospital $hospital, string $name, ImportType $type = ImportType::ENCOUNTER): Import
    {
        return ImportFactory::createOne([
            'name' => $name,
            'hospital' => $hospital,
            'createdBy' => UserFactory::createOne(),
            'type' => $type,
            'status' => ImportStatus::COMPLETED,
            'filePath' => 'dummy/path',
        ]);
    }

    /**
     * Visit on $day at 08:15, anchored on the first assessment contact.
     */
    private function visitOn(Hospital $hospital, Import $import, string $day, int $keyGeneration = 1): Encounter
    {
        return new Encounter(
            hospital: $hospital,
            import: $import,
            keyGeneration: $keyGeneration,
            visitFingerprint: self::fingerprintFor($day),
            caseKey: hash('sha256', 'case|'.$day),
            age: 54,
            sex: Sex::Female,
            times: new EncounterTimes(firstAssessmentContactAt: new \DateTimeImmutable($day.' 08:15')),
        );
    }

    /**
     * Inserts one visit per day and returns the outcome per day.
     *
     * @param list<string> $days
     *
     * @return array<string, EncounterInsertOutcome>
     */
    private function importVisits(Hospital $hospital, Import $import, array $days): array
    {
        $outcomes = [];
        foreach ($days as $day) {
            $outcomes[$day] = $this->encounterRepository()->insertIfNew($this->visitOn($hospital, $import, $day));
        }

        return $outcomes;
    }

    /**
     * Visit days per owning import name, ordered by day.
     *
     * @return array<string, list<string>>
     */
    private function visitDaysByImport(Hospital $hospital): array
    {
        $rows = $this->encounterEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT i.name, e.anchor_at FROM encounter e JOIN import i ON i.id = e.import_id WHERE e.hospital_id = ? ORDER BY e.anchor_at',
            [$hospital->getId()],
        );

        $days = [];
        foreach ($rows as $row) {
            $days[(string) $row['name']][] = substr((string) $row['anchor_at'], 0, 10);
        }

        ksort($days);

        return $days;
    }

    private static function fingerprintFor(string $day): string
    {
        return hash('sha256', 'visit|'.$day);
    }

    private function encounterRepository(): EncounterRepository
    {
        $repository = self::getContainer()->get(EncounterRepository::class);
        self::assertInstanceOf(EncounterRepository::class, $repository);

        return $repository;
    }

    private function encounterEntityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
