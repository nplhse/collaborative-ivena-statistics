<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Tests\Integration\Application\Curation;

use App\Tests\Support\Encounter\CurationPackageData;
use Cois\Plugin\EdVisit\Application\Catalog\MtsCatalog;
use Cois\Plugin\EdVisit\Application\Catalog\MtsCatalogCuration;
use Cois\Plugin\EdVisit\Application\Contract\PlausibilityRepositoryInterface;
use Cois\Plugin\EdVisit\Application\Curation\CurationExporter;
use Cois\Plugin\EdVisit\Application\Curation\Import\CurationConflict;
use Cois\Plugin\EdVisit\Application\Curation\Import\CurationImportApplier;
use Cois\Plugin\EdVisit\Application\Curation\Import\CurationImportPlan;
use Cois\Plugin\EdVisit\Application\Curation\Import\CurationImportPlanner;
use Cois\Plugin\EdVisit\Application\Curation\Package\CurationPackage;
use Cois\Plugin\EdVisit\Application\Curation\Package\CurationPackageReader;
use Cois\Plugin\EdVisit\Application\Curation\Package\CurationPackageWriter;
use Cois\Plugin\EdVisit\Application\Plausibility\PlausibilitySnapshots;
use Cois\Plugin\EdVisit\Domain\Entity\MtsAlias;
use Cois\Plugin\EdVisit\Domain\Entity\MtsDiagram;
use Cois\Plugin\EdVisit\Domain\Entity\PlausibilitySnapshot;
use Cois\Plugin\EdVisit\Domain\Enum\MtsCatalogKind;
use Cois\Plugin\EdVisit\Domain\Enum\MtsCatalogSource;
use Cois\Plugin\EdVisit\Domain\Enum\MtsCatalogStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Add-only import of an encounter curation package (#1198) against the seeded catalog (40 diagrams, 70 indicators,
 * no aliases or assignments) and the seeded problem areas (24, one seed draft snapshot).
 */
#[ResetDatabase]
final class CurationImportTest extends KernelTestCase
{
    private CurationImportPlanner $planner;

    private CurationImportApplier $applier;

    private EntityManagerInterface $entityManager;

    private Connection $connection;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->planner = self::getContainer()->get(CurationImportPlanner::class);
        $this->applier = self::getContainer()->get(CurationImportApplier::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testImportIntoANewInstanceAddsEverythingMissing(): void
    {
        $package = $this->package(CurationPackageData::newInstance());
        $plan = $this->planner->plan($package);

        self::assertFalse($plan->hasConflicts());
        self::assertSame([
            'diagrams' => ['new' => 2, 'unchanged' => 1, 'conflict' => 0],
            'indicators' => ['new' => 1, 'unchanged' => 0, 'conflict' => 0],
            'aliases' => ['new' => 2, 'unchanged' => 0, 'conflict' => 0],
            'assignments' => ['new' => 1, 'unchanged' => 0, 'conflict' => 0],
            'problem_areas' => ['new' => 1, 'unchanged' => 0, 'conflict' => 0],
            'area_pzc' => ['new' => 2, 'unchanged' => 0, 'conflict' => 0],
            'area_rules' => ['new' => 1, 'unchanged' => 0, 'conflict' => 0],
        ], $plan->counts);
        self::assertSame(10, $plan->additionCount());
        $draftId = $plan->seedDraftId;
        self::assertNotNull($draftId, 'the seed migration leaves a seed draft');

        $this->applier->apply($plan, $package);

        $catalog = self::getContainer()->get(MtsCatalog::class);
        self::assertCount(42, $catalog->diagrams());
        self::assertCount(71, $catalog->indicators());
        self::assertSame('augenprobleme_bei_kindern', $catalog->resolve(MtsCatalogKind::Diagram, 'augenprobleme_kinder')?->code);
        self::assertSame('hautprobleme', $catalog->resolve(MtsCatalogKind::Diagram, 'hautproblem_alt')?->code, 'the code of a former merge resolves as on the source');
        self::assertSame('Eye problems in children', $catalog->diagram('augenprobleme_bei_kindern')?->englishName);
        self::assertSame(['juckreiz'], $catalog->indicatorCodesOf('hautprobleme'));

        $entry = $this->connection->fetchAssociative("SELECT source, status FROM encounter_mts_diagram WHERE code = 'hautprobleme'");
        self::assertSame(['source' => 'mts_edition', 'status' => 'curated'], $entry);

        // The seed draft is rebuilt from all areas: the new area gives one fallback cell and one exact cell per PZC.
        self::assertSame($draftId, $this->seedDraft()?->getId());
        self::assertSame([
            [null, 'haut_und_augen', 'hautprobleme', 3],
            [990, null, 'hautprobleme', 3],
            [991, null, 'hautprobleme', 3],
        ], $this->cells($draftId, 'hautprobleme'));

        self::assertSame([
            'encounter.curation.alias_created' => 2,
            'encounter.curation.area_created' => 4, // area, two PZC codes, one rule
            'encounter.curation.assignment_created' => 1,
            'encounter.curation.entry_created' => 3,
        ], $this->auditIntents());
    }

    public function testImportAddsMissingCodesAndRulesToAnExistingAreaAndKeepsTheRest(): void
    {
        // Activate the seed draft, so the import has to create a new one and must not touch the active snapshot.
        $active = $this->seedDraft();
        self::assertInstanceOf(PlausibilitySnapshot::class, $active);
        self::getContainer()->get(PlausibilitySnapshots::class)->activate($active);
        $activeCells = $this->cells((int) $active->getId(), 'allergie');

        $package = $this->package(CurationPackageData::package(
            diagrams: [CurationPackageData::seededAllergy(), ...CurationPackageData::seededTraumaDiagrams()],
            areas: [CurationPackageData::seededAbdominalTrauma([241, 242, 992], [
                ['code' => 'abdominelle_schmerzen_bei_erwachsenen', 'points' => 3],
                ['code' => 'allergie', 'points' => 2],
                ['code' => 'schweres_trauma', 'points' => 3],
            ])],
        ));
        $plan = $this->planner->plan($package);

        self::assertSame(['new' => 0, 'unchanged' => 1, 'conflict' => 0], $plan->counts['problem_areas']);
        self::assertSame(['new' => 1, 'unchanged' => 2, 'conflict' => 0], $plan->counts['area_pzc']);
        self::assertSame(['new' => 1, 'unchanged' => 2, 'conflict' => 0], $plan->counts['area_rules']);
        self::assertNull($plan->seedDraftId, 'no seed draft: a new one is created');
        self::assertCount(1, $plan->extensions);
        self::assertSame([992], $plan->extensions[0]->pzc);

        $this->applier->apply($plan, $package);

        $area = self::getContainer()->get(PlausibilityRepositoryInterface::class)->findAreaByCode('abdominaltrauma');
        self::assertNotNull($area);
        self::assertSame([241, 242, 992], $area->pzcCodes());
        self::assertSame(['abdominelle_schmerzen_bei_erwachsenen' => 3, 'allergie' => 2, 'schweres_trauma' => 3], $area->diagramPoints());

        $draft = $this->seedDraft();
        self::assertInstanceOf(PlausibilitySnapshot::class, $draft);
        self::assertNotSame($active->getId(), $draft->getId());
        self::assertContains([992, null, 'allergie', 2], $this->cells((int) $draft->getId(), 'allergie'));
        self::assertSame($activeCells, $this->cells((int) $active->getId(), 'allergie'), 'the active snapshot is unchanged');
        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM encounter_plausibility_snapshot WHERE id = ?', [$active->getId()]));
        // One PZC row and one rule row; the area row itself is unchanged.
        self::assertSame(['encounter.curation.area_extended' => 2, 'encounter.curation.seed_draft_rebuilt' => 1], $this->auditIntents());
    }

    public function testExistingAssignmentsAndAreasOnlyTheTargetHasAreKept(): void
    {
        $this->import(CurationPackageData::newInstance());
        $areas = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM encounter_plausibility_area');

        // A smaller package without the assignment and without the new area.
        $this->import(CurationPackageData::package(diagrams: [CurationPackageData::seededAllergy()]));

        self::assertSame(['juckreiz'], self::getContainer()->get(MtsCatalog::class)->indicatorCodesOf('hautprobleme'));
        self::assertSame($areas, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM encounter_plausibility_area'));
        self::assertSame(25, $areas);
    }

    public function testSecondImportOfTheSamePackageChangesNothing(): void
    {
        $this->import(CurationPackageData::newInstance());
        $audit = $this->auditCount();
        $cells = $this->connection->fetchFirstColumn('SELECT id FROM encounter_plausibility_cell ORDER BY id');

        $package = $this->package(CurationPackageData::newInstance());
        $plan = $this->planner->plan($package);
        $this->applier->apply($plan, $package);

        self::assertSame(0, $plan->additionCount());
        self::assertSame(['new' => 0, 'unchanged' => 2, 'conflict' => 0], $plan->counts['aliases']);
        self::assertSame($audit, $this->auditCount(), 'no audit entries');
        self::assertSame($cells, $this->connection->fetchFirstColumn('SELECT id FROM encounter_plausibility_cell ORDER BY id'), 'the seed draft is not rebuilt');
    }

    public function testAnyConflictStopsTheImportBeforeAnythingIsWritten(): void
    {
        $package = $this->package(CurationPackageData::package(
            diagrams: [
                CurationPackageData::entry('allergie', 'Allergie (Erwachsene)', 'Allergy', 'orbis_export'),
                CurationPackageData::entry('kieferprobleme', 'Kieferprobleme'),
                ...CurationPackageData::seededTraumaDiagrams(),
            ],
            // PZC 321 belongs to the seeded area "Anaphylaxie".
            areas: [CurationPackageData::seededAbdominalTrauma([241, 242, 321])],
        ));
        $plan = $this->planner->plan($package);

        self::assertSame(['new' => 1, 'unchanged' => 2, 'conflict' => 1], $plan->counts['diagrams']);
        self::assertSame(['new' => 0, 'unchanged' => 2, 'conflict' => 1], $plan->counts['area_pzc']);
        self::assertSame([
            ['diagrams[0]', 'Another curated diagram has this code on the target.', '"Allergie (Erwachsene)" / "Allergy" / orbis_export', '"Allergie" / "Allergy" / orbis_export'],
            ['problem_areas[0].pzc[2]', 'PZC 321 belongs to another problem area on the target; the import does not move PZC codes.', 'abdominaltrauma', 'anaphylaxie'],
        ], $this->conflicts($plan));

        $audit = $this->auditCount();
        try {
            $this->applier->apply($plan, $package);
            self::fail('A plan with conflicts must not be applied.');
        } catch (\LogicException) {
        }

        self::assertCount(40, self::getContainer()->get(MtsCatalog::class)->diagrams(), 'the new diagram was not written either');
        self::assertSame([241, 242], self::getContainer()->get(PlausibilityRepositoryInterface::class)->findAreaByCode('abdominaltrauma')?->pzcCodes());
        self::assertSame($audit, $this->auditCount());
    }

    public function testOtherPointsAndAnAliasWithAnotherTargetAreConflicts(): void
    {
        $curation = self::getContainer()->get(MtsCatalogCuration::class);
        $curation->createAlias(new MtsAlias(MtsCatalogKind::Diagram, '', 'Allergien', 'allergie'));

        $plan = $this->planner->plan($this->package(CurationPackageData::package(
            diagrams: [CurationPackageData::seededAllergy(), CurationPackageData::entry('stuerze', 'Stürze', 'Falls', 'orbis_export'), ...CurationPackageData::seededTraumaDiagrams()],
            aliases: [['kind' => 'diagram', 'code' => 'allergien', 'label' => 'Allergien', 'target_code' => 'stuerze']],
            areas: [CurationPackageData::seededAbdominalTrauma(rules: [
                ['code' => 'abdominelle_schmerzen_bei_erwachsenen', 'points' => 2],
                ['code' => 'schweres_trauma', 'points' => 3],
            ])],
        )));

        self::assertSame([
            ['aliases[0]', 'An alias with this code has another label or target on the target.', '"Allergien" → stuerze', '"Allergien" → allergie'],
            ['problem_areas[0].diagrams[0]', 'The rule for diagram "abdominelle_schmerzen_bei_erwachsenen" has other points on the target.', 'abdominelle_schmerzen_bei_erwachsenen: 2', 'abdominelle_schmerzen_bei_erwachsenen: 3'],
        ], $this->conflicts($plan));
    }

    public function testRoleMismatchesAndOtherAreaNamesAreConflicts(): void
    {
        self::getContainer()->get(MtsCatalogCuration::class)->createAlias(new MtsAlias(MtsCatalogKind::Diagram, '', 'Allergien', 'allergie'));

        $plan = $this->planner->plan($this->package(CurationPackageData::package(
            diagrams: [CurationPackageData::seededAllergy(), CurationPackageData::entry('allergien', 'Allergien'), ...CurationPackageData::seededTraumaDiagrams()],
            aliases: [['kind' => 'diagram', 'code' => 'stuerze', 'label' => 'Stürze', 'target_code' => 'allergie']],
            areas: [CurationPackageData::area('abdominaltrauma', 'Abdominaltrauma', 'Abdominal injury', [241, 242], [
                ['code' => 'abdominelle_schmerzen_bei_erwachsenen', 'points' => 3],
                ['code' => 'schweres_trauma', 'points' => 3],
            ])],
        )));

        self::assertSame([
            ['diagrams[1]', 'The code is an alias of a diagram on the target.', '"Allergien" / – / manual', 'alias "Allergien" → allergie'],
            ['aliases[0]', 'The code is a curated diagram on the target.', '"Stürze" → allergie', 'curated: "Stürze" / "Falls" / orbis_export'],
            ['problem_areas[0]', 'Another problem area has this code on the target.', '"Abdominaltrauma" / "Abdominal injury"', '"Abdominaltrauma" / "Abdominal trauma"'],
        ], $this->conflicts($plan));
    }

    public function testProvisionalEntriesAreConflictsUntilTheyAreCuratedOrMergedTheSameWay(): void
    {
        // The target saw both codes in encounter imports before the package arrives.
        $skin = $this->provisional('hautprobleme');
        $oldSkin = $this->provisional('hautproblem_alt');
        $data = CurationPackageData::newInstance();

        $plan = $this->planner->plan($this->package($data));

        self::assertSame([
            ['diagrams[2]', 'The code is a provisional diagram on the target.', '"Hautprobleme" / – / mts_edition', 'provisional: "hautprobleme" / – / first_seen'],
            ['aliases[1]', 'The code is a provisional diagram on the target.', '"Hautproblem (alt)" → hautprobleme', 'provisional: "hautproblem_alt" / – / first_seen'],
        ], $this->conflicts($plan));
        self::assertSame([
            'Curate the entry in the back office: German name "Hautprobleme", English name empty, source mts_edition, status curated; then import again.',
            'Set its German name to "Hautproblem (alt)" and merge it in the back office into "hautprobleme"; then import again.',
        ], array_map(static fn (CurationConflict $conflict): ?string => $conflict->resolution, $plan->conflicts));

        // Resolved through the curation exactly as the hints say.
        $curation = self::getContainer()->get(MtsCatalogCuration::class);
        $skin->setGermanName('Hautprobleme');
        $skin->setSource(MtsCatalogSource::MtsEdition);
        $skin->setStatus(MtsCatalogStatus::Curated);
        $curation->updateEntry($skin);
        $oldSkin->setGermanName('Hautproblem (alt)');
        $curation->updateEntry($oldSkin);
        $curation->merge($oldSkin, $skin);

        $plan = $this->planner->plan($this->package($data));

        self::assertFalse($plan->hasConflicts());
        self::assertSame(['new' => 1, 'unchanged' => 2, 'conflict' => 0], $plan->counts['diagrams']);
        self::assertSame(['new' => 1, 'unchanged' => 1, 'conflict' => 0], $plan->counts['aliases']);
    }

    public function testOwnExportImportsBackUnchangedIncludingItsMerges(): void
    {
        $curation = self::getContainer()->get(MtsCatalogCuration::class);
        $merged = $this->provisional('allergische_reaktion');
        $allergy = self::getContainer()->get(EntityManagerInterface::class)->getRepository(MtsDiagram::class)->findOneBy(['code' => 'allergie']);
        self::assertInstanceOf(MtsDiagram::class, $allergy);
        $curation->merge($merged, $allergy);
        $this->import(CurationPackageData::newInstance());

        $exported = self::getContainer()->get(CurationExporter::class)->export()->package;
        $writer = self::getContainer()->get(CurationPackageWriter::class);
        $package = $this->package(json_decode($writer->encode($exported), true, 512, \JSON_THROW_ON_ERROR));
        $plan = $this->planner->plan($package);

        self::assertFalse($plan->hasConflicts());
        self::assertSame(0, $plan->additionCount());
        self::assertSame(['new' => 0, 'unchanged' => 3, 'conflict' => 0], $plan->counts['aliases'], 'the merge alias, with its merged entry under the same code, is unchanged');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function import(array $data): void
    {
        $package = $this->package($data);
        $this->applier->apply($this->planner->plan($package), $package);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function package(array $data): CurationPackage
    {
        $result = self::getContainer()->get(CurationPackageReader::class)->readJson(CurationPackageData::json($data));
        self::assertTrue($result->isValid(), print_r($result->violations, true));

        return $result->package;
    }

    private function provisional(string $code): MtsDiagram
    {
        $diagram = new MtsDiagram($code, $code, MtsCatalogSource::FirstSeen, MtsCatalogStatus::Provisional, new \DateTimeImmutable('2026-11-01'));
        $this->entityManager->persist($diagram);
        $this->entityManager->flush();
        self::getContainer()->get(MtsCatalog::class)->reset();

        return $diagram;
    }

    private function seedDraft(): ?PlausibilitySnapshot
    {
        return self::getContainer()->get(PlausibilityRepositoryInterface::class)->seedDraft();
    }

    /**
     * @return list<array{0: ?int, 1: ?string, 2: string, 3: int}> pzc, area, diagram, points
     */
    private function cells(int $snapshotId, string $diagram): array
    {
        return array_map(
            static fn (array $row): array => [null === $row[0] ? null : (int) $row[0], null === $row[1] ? null : (string) $row[1], (string) $row[2], (int) $row[3]],
            $this->connection->fetchAllNumeric(
                'SELECT pzc, area_code, diagram_code, points FROM encounter_plausibility_cell WHERE snapshot_id = ? AND diagram_code = ? ORDER BY pzc NULLS FIRST, area_code',
                [$snapshotId, $diagram],
            ),
        );
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function conflicts(CurationImportPlan $plan): array
    {
        return array_map(static fn (CurationConflict $conflict): array => [$conflict->path, $conflict->message, $conflict->package, $conflict->target], $plan->conflicts);
    }

    /**
     * @return array<string, int> entries per intent of the import
     */
    private function auditIntents(): array
    {
        /** @var array<string, int|string> $rows */
        $rows = $this->connection->fetchAllKeyValue("SELECT intent, COUNT(*) FROM audit_log WHERE intent LIKE 'encounter.curation.%' GROUP BY intent ORDER BY intent");

        return array_map(static fn (int|string $count): int => (int) $count, $rows);
    }

    private function auditCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM audit_log');
    }
}
