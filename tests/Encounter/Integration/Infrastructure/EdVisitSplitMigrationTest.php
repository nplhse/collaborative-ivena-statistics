<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Infrastructure;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Import\Domain\Enum\ImportType;
use App\Tests\Support\Encounter\AttachedRecordFixtures;
use App\Tests\Support\Encounter\EdVisitFileBuilder;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use App\Tests\Support\Encounter\EncounterImportRunner;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\Tests\Support\Encounter\MatchingDataFixtures;
use Cois\Plugin\EdVisit\Application\Contract\EncounterOverviewReaderInterface;
use Cois\Plugin\EdVisit\Application\Contract\HospitalCourseReaderInterface;
use Cois\Plugin\EdVisit\Application\Overview\EncounterPeriod;
use Cois\Plugin\EdVisit\Application\Overview\ProcessInterval;
use Cois\Plugin\EdVisit\Domain\Entity\EdVisit;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\DependencyFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The split of `ed_visit` out of `encounter` (#1300) is 1:1 and fully reversible for today's data: down moves
 * every visit back into the encounter with the same id, uid and ED fields, up splits it again. The ED matching
 * history, the effective IVENA assignment and the encounter analytics are the same before and after.
 */
#[ResetDatabase]
final class EdVisitSplitMigrationTest extends KernelTestCase
{
    use AttachedRecordFixtures;
    use EncounterDataFixtures;
    use EncounterImportRunner;
    use EncounterTestFixtures;
    use Factories;
    use MatchingDataFixtures;

    /** The migrations directory is not autoloaded for the static analysis, so the class is built from its version. */
    private const string MIGRATION_VERSION = '20261116183100';

    /** ED columns that move between the two tables. */
    private const string ED_COLUMNS = 'visit_uid, visit_fingerprint, import_id, anchor_source, arrival_mode, arrival_route, referral_source, admission_type, case_status, work_accident, tetanus_protected, isolation, ed_admission_at, first_assessment_contact_at, first_physician_at, ed_discharge_at, mts_category, mts_diagram, mts_indicator, mts_pain_score, disposition_code, disposition_care_level, admitting_department_id, vitals_reference, vitals_heart_rate';

    private Hospital $hospital;

    /** @var array<string, int> */
    private array $visits;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->hospital = $this->hospital($this->user());
        $uploader = $this->user();
        $this->encounterGrant($this->hospital, $uploader, [EncounterGrantAction::Upload]);
        $allocations = $this->encounterImport($this->hospital, 'allocations', ImportType::ALLOCATION);
        $allocationX = (int) $this->allocationAt($this->hospital, $allocations, '08:30')->getId();

        $importId = $this->uploadEncounterFile($this->hospital, $uploader, EdVisitFileBuilder::create()
            ->visit('a', '2026-10-12T08:15', overrides: ['mts_category' => 'orange', 'disposition' => 'admitted'])
            ->visit('b', '2026-10-12T09:15')
            ->visit('c', '2026-10-13T15:40')
            ->build(), 'ed visits');
        $this->visits = $this->visitIdsOfImport($importId, 'a', 'b', 'c');
        $this->linkVisit($allocationX, $this->visits['a']);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeEncounterFiles();
        parent::tearDown();
    }

    public function testDownAndUpKeepIdsUidsEdFieldsAssignmentsAndAnalytics(): void
    {
        $visits = $this->splitVisits();
        $encounters = $this->encounters();
        $matches = $this->matchRows();
        $analytics = $this->analytics();
        self::assertCount(3, $visits);
        self::assertNotNull($encounters[$this->visits['a']]['allocation_id']);

        $this->migrate(up: false);

        self::assertSame($visits, $this->joinedVisitsBeforeTheSplit(), 'Down moves every visit back with the same id, uid and ED fields.');
        self::assertSame($matches, $this->matchRows());

        $this->migrate(up: true);

        self::assertSame($visits, $this->splitVisits(), 'Up splits them again with the same values.');
        self::assertSame($encounters, $this->encounters());
        self::assertSame($matches, $this->matchRows());
        self::assertEquals($analytics, $this->analytics());

        // The visit URL (/explore/ed-visits/visit/{uid}) still resolves to the same visit.
        $reader = self::getContainer()->get(HospitalCourseReaderInterface::class);
        self::assertInstanceOf(HospitalCourseReaderInterface::class, $reader);
        $this->entityManager()->clear();
        $visit = $reader->visitByUid(Uuid::fromString($visits[$this->visits['b']]['visit_uid']));
        self::assertInstanceOf(EdVisit::class, $visit);
        self::assertSame($this->visits['b'], $visit->getId());
    }

    public function testDownDeletesEncountersWithoutEdVisit(): void
    {
        $connection = $this->connection();
        $orphan = (int) $connection->fetchOne(
            "INSERT INTO encounter (hospital_id, key_generation, case_key, anchor_at, age, sex, created_at) VALUES (?, 1, ?, '2026-10-12 12:00:00', 40, 'F', '2026-10-12 12:00:00') RETURNING id",
            [$this->hospital->getId(), hash('sha256', 'orphan')],
        );

        $this->migrate(up: false);

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM encounter WHERE id = ?', [$orphan]), 'Not expressible in the old model: lost on downgrade (upgrading.md).');
        self::assertSame(3, (int) $connection->fetchOne('SELECT COUNT(*) FROM encounter WHERE hospital_id = ?', [$this->hospital->getId()]));
    }

    /**
     * @return array<int, array<string, mixed>> ED fields with the encounter base data, by encounter id
     */
    private function splitVisits(): array
    {
        return $this->byId($this->connection()->fetchAllAssociative(
            'SELECT e.id, e.case_key, e.anchor_at, e.age, e.sex, '.$this->prefixed('v').' FROM encounter e JOIN ed_visit v ON v.encounter_id = e.id ORDER BY e.id',
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function joinedVisitsBeforeTheSplit(): array
    {
        return $this->byId($this->connection()->fetchAllAssociative(
            'SELECT e.id, e.case_key, e.anchor_at, e.age, e.sex, '.$this->prefixed('e').' FROM encounter e ORDER BY e.id',
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function encounters(): array
    {
        return $this->byId($this->connection()->fetchAllAssociative(
            'SELECT id, hospital_id, key_generation, case_key, anchor_at, age, sex, created_at, allocation_id, allocation_assigned_at FROM encounter ORDER BY id',
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function matchRows(): array
    {
        return $this->connection()->fetchAllAssociative('SELECT id, allocation_id, encounter_id, status, score FROM encounter_match ORDER BY id');
    }

    /**
     * @return array<string, mixed>
     */
    private function analytics(): array
    {
        $reader = self::getContainer()->get(EncounterOverviewReaderInterface::class);
        self::assertInstanceOf(EncounterOverviewReaderInterface::class, $reader);
        $hospitalId = (int) $this->hospital->getId();
        $period = EncounterPeriod::allTime();

        return [
            'coverage' => $reader->coverage($hospitalId, $period),
            'routes' => $reader->visitsByRouteAndLink($hospitalId, $period),
            'categories' => $reader->visitsByCategory($hospitalId, $period),
            'dispositions' => $reader->visitsByDisposition($hospitalId, $period),
            'process' => $reader->processTime($hospitalId, $period, ProcessInterval::AdmissionToAssessment),
        ];
    }

    private function prefixed(string $alias): string
    {
        return implode(', ', array_map(static fn (string $column): string => $alias.'.'.$column, explode(', ', self::ED_COLUMNS)));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    private function byId(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['id']] = $row;
        }

        return $result;
    }

    private function migrate(bool $up): void
    {
        $dependencyFactory = self::getContainer()->get('doctrine.migrations.dependency_factory');
        self::assertInstanceOf(DependencyFactory::class, $dependencyFactory);
        self::assertTrue($dependencyFactory->getMigrationRepository()->hasMigration($this->migrationClass()));

        // A fresh instance per direction: planned SQL accumulates on the migration object.
        $migration = $dependencyFactory->getMigrationFactory()->createVersion($this->migrationClass());
        $up ? $migration->up(new Schema()) : $migration->down(new Schema());

        foreach ($migration->getSql() as $query) {
            $this->connection()->executeStatement($query->getStatement());
        }
    }

    private function migrationClass(): string
    {
        return sprintf('DoctrineMigrations\\Version%s', self::MIGRATION_VERSION);
    }
}
