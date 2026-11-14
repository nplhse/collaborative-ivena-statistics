<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Purger;

use Doctrine\Bundle\FixturesBundle\Loader\SymfonyFixturesLoader;
use Doctrine\Bundle\FixturesBundle\Purger\ORMPurgerFactory;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * A fixture load keeps the curated problem areas of the plausibility table and rebuilds the seed draft from them
 * (ADR 033, #1219). The load runs through the configured purger factory and ORMExecutor like `make fixtures`.
 *
 * Before the load, the area `thoraxtrauma` (PZC 231, 232, 233; one rule × `schweres_trauma`, +3 in the seed) is
 * curated: PZC 233 removed and the rule set to +1. Its part of the rebuilt draft is therefore 2 memberships, 1
 * fallback cell and 2 exact cells, all with 1 point. The seed snapshot is activated and a learned draft added, so
 * the load has an active and a learned snapshot to drop.
 */
#[ResetDatabase]
final class MaterializedViewAwareOrmPurgerTest extends KernelTestCase
{
    #[Test]
    public function fixtureLoadKeepsCuratedAreasAndRebuildsOneInactiveSeedDraft(): void
    {
        self::bootKernel();
        $oldSnapshots = $this->curateThoraxTraumaAndAddSnapshots();
        $diagrams = $this->rows('encounter_mts_diagram');

        $this->load();

        self::assertGreaterThan(0, $this->rows('hospital'), 'the fixtures were loaded after the purge');
        $this->assertCuratedThoraxTrauma();
        self::assertSame($diagrams, $this->rows('encounter_mts_diagram'), 'the MTS catalog is kept');

        $snapshots = $this->connection()->fetchAllAssociative('SELECT id, status, origin, activated_at FROM encounter_plausibility_snapshot');
        self::assertCount(1, $snapshots);
        self::assertSame(['draft', 'seed', null], [$snapshots[0]['status'], $snapshots[0]['origin'], $snapshots[0]['activated_at']]);
        self::assertNotContains((int) $snapshots[0]['id'], $oldSnapshots);
        $this->assertDraftReflectsAreas((int) $snapshots[0]['id']);
    }

    #[Test]
    public function loadingTwiceKeepsOneSeedDraftAndTheCuration(): void
    {
        self::bootKernel();
        $this->curateThoraxTraumaAndAddSnapshots();

        $this->load();
        $first = (int) $this->connection()->fetchOne('SELECT id FROM encounter_plausibility_snapshot');
        $cells = $this->rows('encounter_plausibility_cell');
        $codes = $this->rows('encounter_plausibility_snapshot_code');

        $this->load();

        self::assertSame(1, $this->rows('encounter_plausibility_snapshot'));
        $second = (int) $this->connection()->fetchOne("SELECT id FROM encounter_plausibility_snapshot WHERE status = 'draft' AND origin = 'seed'");
        self::assertNotSame($first, $second);
        self::assertSame($cells, $this->rows('encounter_plausibility_cell'));
        self::assertSame($codes, $this->rows('encounter_plausibility_snapshot_code'));
        $this->assertCuratedThoraxTrauma();
        $this->assertDraftReflectsAreas($second);
    }

    /**
     * @return list<int> the snapshot ids before the load
     */
    private function curateThoraxTraumaAndAddSnapshots(): array
    {
        $connection = $this->connection();
        self::assertSame(
            [231, 232, 233],
            array_map(intval(...), $connection->fetchFirstColumn("SELECT c.pzc FROM encounter_plausibility_area_code c JOIN encounter_plausibility_area a ON a.id = c.area_id WHERE a.code = 'thoraxtrauma' ORDER BY c.pzc")),
            'seed of Version20261113235145',
        );
        $connection->executeStatement('DELETE FROM encounter_plausibility_area_code WHERE pzc = 233');
        $connection->executeStatement("UPDATE encounter_plausibility_area_diagram SET points = 1 WHERE area_id = (SELECT id FROM encounter_plausibility_area WHERE code = 'thoraxtrauma')");

        $connection->executeStatement("UPDATE encounter_plausibility_snapshot SET status = 'active', activated_at = LOCALTIMESTAMP(0) WHERE origin = 'seed'");
        $connection->executeStatement("INSERT INTO encounter_plausibility_snapshot (status, origin, created_at) VALUES ('draft', 'learned', LOCALTIMESTAMP(0))");
        $connection->executeStatement("INSERT INTO encounter_plausibility_cell (snapshot_id, pzc, area_code, diagram_code, indicator_code, points, source, positives, negatives) VALUES ((SELECT MAX(id) FROM encounter_plausibility_snapshot), 233, NULL, 'schweres_trauma', NULL, 2, 'learned', 5, 1)");

        $snapshots = array_map(intval(...), $connection->fetchFirstColumn('SELECT id FROM encounter_plausibility_snapshot ORDER BY id'));
        self::assertCount(2, $snapshots);

        return $snapshots;
    }

    private function assertCuratedThoraxTrauma(): void
    {
        $connection = $this->connection();
        self::assertSame(
            [231, 232],
            array_map(intval(...), $connection->fetchFirstColumn("SELECT c.pzc FROM encounter_plausibility_area_code c JOIN encounter_plausibility_area a ON a.id = c.area_id WHERE a.code = 'thoraxtrauma' ORDER BY c.pzc")),
        );
        self::assertSame(
            [['diagram' => 'schweres_trauma', 'points' => 1]],
            $connection->fetchAllAssociative("SELECT d.code AS diagram, r.points FROM encounter_plausibility_area_diagram r JOIN encounter_plausibility_area a ON a.id = r.area_id JOIN encounter_mts_diagram d ON d.id = r.diagram_id WHERE a.code = 'thoraxtrauma'"),
        );

        // The draft: 2 memberships, 1 fallback cell and 2 exact cells, each with 1 point; PZC 233 is in no cell.
        self::assertSame([231, 232], array_map(intval(...), $connection->fetchFirstColumn("SELECT pzc FROM encounter_plausibility_snapshot_code WHERE area_code = 'thoraxtrauma' ORDER BY pzc")));
        self::assertSame(
            [
                ['pzc' => null, 'area_code' => 'thoraxtrauma', 'diagram_code' => 'schweres_trauma', 'points' => 1, 'source' => 'curated'],
                ['pzc' => 231, 'area_code' => null, 'diagram_code' => 'schweres_trauma', 'points' => 1, 'source' => 'curated'],
                ['pzc' => 232, 'area_code' => null, 'diagram_code' => 'schweres_trauma', 'points' => 1, 'source' => 'curated'],
            ],
            $connection->fetchAllAssociative("SELECT pzc, area_code, diagram_code, points, source FROM encounter_plausibility_cell WHERE area_code = 'thoraxtrauma' OR pzc IN (231, 232, 233) ORDER BY pzc NULLS FIRST"),
        );
    }

    /**
     * Every cell and membership of the draft comes from a kept area, code and rule, and every kept rule has its cells.
     */
    private function assertDraftReflectsAreas(int $snapshotId): void
    {
        $connection = $this->connection();
        $sorted = static function (array $rows): array {
            $rows = array_map(static fn (array $row): string => implode('|', array_map(strval(...), $row)), $rows);
            sort($rows);

            return $rows;
        };

        self::assertSame(
            $sorted($connection->fetchAllNumeric('SELECT c.pzc, a.code FROM encounter_plausibility_area_code c JOIN encounter_plausibility_area a ON a.id = c.area_id')),
            $sorted($connection->fetchAllNumeric('SELECT pzc, area_code FROM encounter_plausibility_snapshot_code WHERE snapshot_id = ?', [$snapshotId])),
        );
        self::assertSame(
            $sorted($connection->fetchAllNumeric('SELECT a.code, d.code, r.points FROM encounter_plausibility_area_diagram r JOIN encounter_plausibility_area a ON a.id = r.area_id JOIN encounter_mts_diagram d ON d.id = r.diagram_id')),
            $sorted($connection->fetchAllNumeric('SELECT area_code, diagram_code, points FROM encounter_plausibility_cell WHERE snapshot_id = ? AND area_code IS NOT NULL', [$snapshotId])),
        );
        self::assertSame(
            $sorted($connection->fetchAllNumeric('SELECT c.pzc, d.code, r.points FROM encounter_plausibility_area_code c JOIN encounter_plausibility_area_diagram r ON r.area_id = c.area_id JOIN encounter_mts_diagram d ON d.id = r.diagram_id')),
            $sorted($connection->fetchAllNumeric('SELECT pzc, diagram_code, points FROM encounter_plausibility_cell WHERE snapshot_id = ? AND pzc IS NOT NULL', [$snapshotId])),
        );
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM encounter_plausibility_cell WHERE snapshot_id <> ?', [$snapshotId]));
    }

    private function load(): void
    {
        $container = self::getContainer();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        /** @var SymfonyFixturesLoader $loader */
        $loader = $container->get('doctrine.fixtures.loader');
        /** @var ORMPurgerFactory $purgerFactory */
        $purgerFactory = $container->get('doctrine.fixtures.purger.orm_purger_factory');

        new ORMExecutor($entityManager, $purgerFactory->createForEntityManager('default', $entityManager, []))->execute($loader->getFixtures(['participation']), false);
        $entityManager->clear();
    }

    private function rows(string $table): int
    {
        return (int) $this->connection()->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table));
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }
}
