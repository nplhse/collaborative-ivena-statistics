<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Encounter;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\DataFixtures\Encounter\Application\EncounterShares;
use App\DataFixtures\Encounter\Application\GeneratedEncounters;
use App\DataFixtures\Encounter\Application\SyntheticEncounterExports;
use App\DataFixtures\Encounter\Application\SyntheticEncounterGenerator;
use App\DataFixtures\Encounter\Application\SyntheticEncounterKey;
use App\DataFixtures\Encounter\Application\SyntheticVisit;
use App\DataFixtures\Encounter\Infrastructure\SyntheticEncounterWriter;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Tests\Support\Encounter\EncounterImportRunner;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\Tests\Support\Encounter\HandMadeAllocations;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Generated encounter data through the #1048 import and the direct writer (#1052).
 *
 * The visits come from the 100 hand-made allocations of HandMadeAllocations, with 10 % late
 * assessments so that both overlapping exports contain some. Expected counts follow from the visits:
 * part 2 repeats the visits of days 11–20; those without late assessment are known visits, those with
 * a late assessment are new (other fingerprint) and reported as suspected duplicates.
 */
#[ResetDatabase]
final class SyntheticEncounterImportTest extends KernelTestCase
{
    use EncounterImportRunner;
    use EncounterTestFixtures;
    use Factories;

    private const int SEED = 740;

    private Hospital $hospital;

    private User $uploader;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->hospital = $this->hospital($this->user());
        $this->uploader = $this->user();
        $this->encounterGrant($this->hospital, $this->uploader, [EncounterGrantAction::Upload]);
        foreach (HandMadeAllocations::DEPARTMENTS as $name) {
            DepartmentFactory::createOne(['name' => $name, 'createdBy' => $this->uploader]);
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeEncounterFiles();
        parent::tearDown();
    }

    public function testOverlappingExportsImportWithoutRejectsAndReportLateAssessmentsAsSuspectedDuplicates(): void
    {
        $generated = $this->generate();
        $key = SyntheticEncounterKey::forHospital(self::SEED, (string) $this->hospital->getPublicId());
        [$first, $second] = SyntheticEncounterExports::overlapping($generated, new \DateTimeImmutable('2026-10-01'));

        $inFirst = [];
        foreach ($first->visits() as $visit) {
            $inFirst[spl_object_id($visit)] = true;
        }
        $known = \count(array_filter($second->visits(), static fn (SyntheticVisit $visit): bool => isset($inFirst[spl_object_id($visit)]) && !$visit->lateAssessment));
        $refilled = \count(array_filter($second->visits(), static fn (SyntheticVisit $visit): bool => isset($inFirst[spl_object_id($visit)]) && $visit->lateAssessment));
        self::assertGreaterThan(0, $known);
        self::assertGreaterThan(0, $refilled, 'Some late assessments lie on days 11–20.');
        self::assertSame(\count($generated->visits), \count($first->rows) + \count($second->rows) - $known - $refilled, 'Every visit is in at least one export.');

        $firstId = $this->upload($first->content($key, $this->toolVersion()), 'part 1');
        $secondId = $this->upload($second->content($key, $this->toolVersion()), 'part 2');

        self::assertSame(ImportStatus::COMPLETED, $this->import($firstId)->getStatus());
        self::assertSame(ImportStatus::COMPLETED, $this->import($secondId)->getStatus());
        $this->assertCounts($firstId, inserted: \count($first->rows), duplicates: 0, suspected: 0);
        $this->assertCounts($secondId, inserted: \count($second->rows) - $known, duplicates: $known, suspected: $refilled);
        self::assertSame(\count($generated->visits) + $refilled, $this->encounterCount($firstId) + $this->encounterCount($secondId));
    }

    public function testWriterStoresEveryVisitWithItsTruth(): void
    {
        $generated = $this->generate();
        $owner = $this->hospital->getOwner();
        self::assertInstanceOf(User::class, $owner);

        $persisted = $this->writer()->write($this->hospital, self::SEED, '2026-10', $generated, $owner, new \DateTimeImmutable('2026-11-01 10:00'));

        self::assertCount(\count($generated->visits), $persisted);
        $importId = (int) $this->connection()->fetchOne('SELECT import_id FROM encounter WHERE id = ?', [$persisted[0]->encounterId]);
        self::assertSame('synthetic/encounters/seed-740/'.$this->hospital->getPublicId().'/2026-10.csv', $this->import($importId)->getFilePath());
        self::assertSame(\count($generated->visits), $this->encounterImportOf($importId)->getInserted());
        self::assertSame('synthetic', $this->encounterImportOf($importId)->getProfile());

        // Arrival routes by group: linked and EMS without allocation are EMS, self and repeat visits self.
        $routes = $this->connection()->fetchAllKeyValue('SELECT arrival_route, COUNT(*) FROM encounter WHERE import_id = ? GROUP BY arrival_route ORDER BY arrival_route', [$importId]);
        $counts = $generated->countsByKind();
        $unknown = $generated->count(static fn (SyntheticVisit $visit): bool => !\in_array($visit->cells['arrival_mode'], ['ambulance', 'patient_transport', 'physician_vehicle', 'self'], true));
        self::assertSame(['ems' => $counts['linked'] + $counts['ems_without_allocation'], 'self' => $counts['self_presenter'] + $counts['repeat_visit'], 'unknown' => $unknown], array_map(intval(...), $routes));

        // The truth maps stored encounters to allocations: same age, sex and ED admission as generated.
        foreach ($persisted as $visit) {
            $row = $this->connection()->fetchAssociative('SELECT age, sex, ed_admission_at FROM encounter WHERE id = ?', [$visit->encounterId]);
            self::assertIsArray($row);
            self::assertSame((int) $visit->visit->cells['age'], (int) $row['age']);
            self::assertSame($visit->visit->cells['sex'], $row['sex']);
            self::assertSame($visit->visit->edAdmissionAt()?->format('Y-m-d H:i:s'), $row['ed_admission_at']);
        }
        self::assertCount(85, array_filter($persisted, static fn (\App\DataFixtures\Encounter\Application\PersistedVisit $visit): bool => null !== $visit->trueAllocationId()));

        $this->expectException(\LogicException::class);
        $this->writer()->write($this->hospital, self::SEED, '2026-10-again', $generated, $owner, new \DateTimeImmutable('2026-11-02 10:00'));
    }

    public function testWriterRefusesAHospitalWithEncounterDataUnderAnotherKey(): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO encounter_key_state (hospital_id, key_generation, active_check_value, created_at) VALUES (?, 1, ?, '2026-11-01 10:00:00')",
            [$this->hospital->getId(), str_repeat('d', 64)],
        );
        $owner = $this->hospital->getOwner();
        self::assertInstanceOf(User::class, $owner);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('another key');
        $this->writer()->write($this->hospital, self::SEED, '2026-10', $this->generate(), $owner, new \DateTimeImmutable('2026-11-01 10:00'));
    }

    private function generate(): GeneratedEncounters
    {
        $generator = self::getContainer()->get(SyntheticEncounterGenerator::class);
        self::assertInstanceOf(SyntheticEncounterGenerator::class, $generator);

        return $generator->generate(
            self::SEED,
            (string) $this->hospital->getPublicId(),
            new \DateTimeImmutable('2026-10-01'),
            new \DateTimeImmutable('2026-10-31'),
            HandMadeAllocations::october2026(),
            new EncounterShares(lateAssessment: 0.10),
        );
    }

    private function upload(string $content, string $name): int
    {
        return $this->uploadEncounterFile($this->hospital, $this->uploader, $content, $name);
    }

    private function assertCounts(int $importId, int $inserted, int $duplicates, int $suspected): void
    {
        $encounterImport = $this->encounterImportOf($importId);
        self::assertSame(
            ['inserted' => $inserted, 'duplicates' => $duplicates, 'skipped' => 0, 'rejected' => 0, 'suspected' => $suspected],
            [
                'inserted' => $encounterImport->getInserted(),
                'duplicates' => $encounterImport->getDuplicates(),
                'skipped' => $encounterImport->getSkipped(),
                'rejected' => $encounterImport->getRejected(),
                'suspected' => $encounterImport->getSuspectedDuplicates(),
            ],
        );
        self::assertNull($encounterImport->getFileRejection());
    }

    private function import(int $importId): Import
    {
        $this->entityManager()->clear();
        $import = $this->entityManager()->find(Import::class, $importId);
        self::assertNotNull($import);

        return $import;
    }

    private function writer(): SyntheticEncounterWriter
    {
        $writer = self::getContainer()->get(SyntheticEncounterWriter::class);
        self::assertInstanceOf(SyntheticEncounterWriter::class, $writer);

        return $writer;
    }

    private function toolVersion(): string
    {
        $version = self::getContainer()->getParameter('app.encounter.data_prep.version');
        self::assertIsString($version);

        return $version;
    }
}
