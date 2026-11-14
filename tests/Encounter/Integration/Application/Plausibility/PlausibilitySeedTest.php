<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Application\Plausibility;

use App\Encounter\Application\Contract\PlausibilityRepositoryInterface;
use App\Encounter\Application\Contract\PlausibilitySnapshotBuilderInterface;
use App\Encounter\Application\Plausibility\PlausibilityHit;
use App\Encounter\Application\Plausibility\PlausibilityLevel;
use App\Encounter\Application\Plausibility\PlausibilityLookup;
use App\Encounter\Domain\Entity\PlausibilitySnapshot;
use App\Encounter\Domain\Enum\PlausibilityCellSource;
use App\Encounter\Domain\Enum\PlausibilitySnapshotOrigin;
use App\Encounter\Domain\Enum\PlausibilitySnapshotStatus;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The curated seed of the migration Version20261113235145 (ADR 033): a-priori problem areas, one draft snapshot with
 * origin `seed`, no active snapshot, no counts. 24 areas with 114 PZC codes and 61 rules (each +3) give 61 fallback
 * cells and one exact cell per PZC × rule of its area: 373.
 */
#[ResetDatabase]
final class PlausibilitySeedTest extends KernelTestCase
{
    private Connection $connection;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testSeedIsOneDraftAndNothingIsActive(): void
    {
        self::assertSame(24, $this->rows('SELECT COUNT(*) FROM encounter_plausibility_area'));
        self::assertSame(114, $this->rows('SELECT COUNT(*) FROM encounter_plausibility_area_code'));
        self::assertSame(61, $this->rows('SELECT COUNT(*) FROM encounter_plausibility_area_diagram WHERE points = 3'));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM encounter_plausibility_area_diagram WHERE points <> 3'));

        $snapshots = self::getContainer()->get(PlausibilityRepositoryInterface::class)->snapshots();
        self::assertCount(1, $snapshots);
        self::assertSame(PlausibilitySnapshotStatus::Draft, $snapshots[0]->getStatus());
        self::assertSame(PlausibilitySnapshotOrigin::Seed, $snapshots[0]->getOrigin());

        self::assertSame(114, $this->rows('SELECT COUNT(*) FROM encounter_plausibility_snapshot_code'));
        self::assertSame(61, $this->rows('SELECT COUNT(*) FROM encounter_plausibility_cell WHERE area_code IS NOT NULL'));
        self::assertSame(373, $this->rows('SELECT COUNT(*) FROM encounter_plausibility_cell WHERE pzc IS NOT NULL'));
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM encounter_plausibility_cell WHERE positives <> 0 OR negatives <> 0 OR source <> 'curated' OR points <> 3"), 'no counts, curated +3 only');
    }

    public function testNoBonusWithoutAnActiveSnapshot(): void
    {
        $lookup = self::getContainer()->get(PlausibilityLookup::class);

        self::assertNull($lookup->activeTable());
        self::assertEquals(PlausibilityHit::none(null), $lookup->bonus(711, 'urologisches_problem'), 'the seed is a draft: nothing changes for matching');
    }

    public function testSeedDraftFollowsTheDecidedMapping(): void
    {
        $lookup = self::getContainer()->get(PlausibilityLookup::class);
        $draft = self::getContainer()->get(PlausibilityRepositoryInterface::class)->seedDraft();
        self::assertInstanceOf(PlausibilitySnapshot::class, $draft);
        $table = $lookup->table((int) $draft->getId());

        $plausible = [
            [711, 'urologisches_problem'], [712, 'hodenschmerz'], [711, 'rueckenschmerz'],
            [273, 'stuerze'], [222, 'kopfverletzung'], [331, 'thoraxschmerz'], [348, 'atemproblem_bei_erwachsenen'],
            [322, 'kollaps'], [421, 'kollaps'], [411, 'krampfanfall'], [361, 'betrunkener_eindruck'],
            [431, 'psychische_erkrankung'], [393, 'diabetes'], [281, 'verbrennungen_und_verbruehungen'],
            [325, 'urologisches_problem'], [373, 'urologisches_problem'], [326, 'stuerze'], [354, 'durchfaelle_und_erbrechen'],
        ];
        foreach ($plausible as [$pzc, $diagram]) {
            self::assertEquals(new PlausibilityHit(3, PlausibilityLevel::Area, $table->snapshotId, PlausibilityCellSource::Curated), $table->lookup($pzc, $diagram), \sprintf('%03d × %s', $pzc, $diagram));
        }

        $neutral = [
            [711, 'thoraxschmerz'], [331, 'stuerze'],
            [329, 'kollaps'], [319, 'atemproblem_bei_erwachsenen'], [369, 'ueberdosierung_und_vergiftung'], [419, 'kollaps'],
            [711, 'unwohlsein_bei_erwachsenen'], [325, 'hautausschlaege'], [521, 'abdominelle_schmerzen_bei_erwachsenen'],
        ];
        foreach ($neutral as [$pzc, $diagram]) {
            self::assertSame(0, $table->lookup($pzc, $diagram)->points, \sprintf('%03d × %s', $pzc, $diagram));
        }

        foreach ([319, 329, 349, 359, 369, 379, 419, 439, 719, 299] as $catchAll) {
            self::assertNull($table->areas()[$catchAll] ?? null, \sprintf('%03d belongs to no area', $catchAll));
        }
    }

    public function testBuilderRebuildsTheSameDraftAsTheMigration(): void
    {
        $draft = self::getContainer()->get(PlausibilityRepositoryInterface::class)->seedDraft();
        self::assertInstanceOf(PlausibilitySnapshot::class, $draft);
        $before = $this->cells();

        self::getContainer()->get(PlausibilitySnapshotBuilderInterface::class)->rebuildFromAreas($draft);

        self::assertSame($before, $this->cells());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cells(): array
    {
        return [
            ...$this->connection->fetchAllAssociative('SELECT pzc, area_code FROM encounter_plausibility_snapshot_code ORDER BY pzc'),
            ...$this->connection->fetchAllAssociative('SELECT pzc, area_code, diagram_code, indicator_code, points, source, positives, negatives FROM encounter_plausibility_cell ORDER BY area_code, pzc, diagram_code'),
        ];
    }

    private function rows(string $sql): int
    {
        return (int) $this->connection->fetchOne($sql);
    }
}
