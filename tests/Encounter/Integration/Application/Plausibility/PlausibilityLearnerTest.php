<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Application\Plausibility;

use App\Encounter\Application\Catalog\MtsCatalog;
use App\Encounter\Application\Contract\MatchCandidateReaderInterface;
use App\Encounter\Application\Contract\MatchValidationReaderInterface;
use App\Encounter\Application\Contract\PlausibilityLearningReaderInterface;
use App\Encounter\Application\Contract\PlausibilityRepositoryInterface;
use App\Encounter\Application\Contract\PlausibilitySnapshotBuilderInterface;
use App\Encounter\Application\Contract\PlausibilityTableReaderInterface;
use App\Encounter\Application\Matching\MatchingSettings;
use App\Encounter\Application\Plausibility\PlausibilityEstimator;
use App\Encounter\Application\Plausibility\PlausibilityException;
use App\Encounter\Application\Plausibility\PlausibilityLearner;
use App\Encounter\Application\Plausibility\PlausibilityLearningReport;
use App\Encounter\Application\Plausibility\PlausibilityLearningSettings;
use App\Encounter\Application\Plausibility\PlausibilityLookup;
use App\Encounter\Application\Plausibility\PlausibilitySnapshots;
use App\Encounter\Domain\Entity\PlausibilitySnapshot;
use App\Encounter\Domain\Enum\PlausibilitySnapshotOrigin;
use App\Encounter\Domain\Enum\PlausibilitySnapshotStatus;
use App\Encounter\Domain\Enum\Sex;
use App\Import\Domain\Enum\ImportType;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use App\Tests\Support\Encounter\MatchingDataFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The learning job against the database (ADR 033, #1155), with a minimum of 1 observation so that a tiny fixture
 * reaches it (smoothing 1, shrinkage 10).
 *
 * The seed snapshot is active: PZC 711 belongs to "urologie", whose rule gives `urologisches_problem` (U) +3. On
 * 12 October 2026 (all fold 0), female 70-year-olds with PZC 711:
 *
 * - A1 10:00 and V1 10:05 (female, U): pass-1 link, eligible. V2 11:00 (female 72, `kopfschmerz`) lies in A1's pass-2
 *   window (+60 min, age +2) and is its only other candidate.
 * - A2 14:00 and V3 14:07 (male, U): 4 − 2 + 2 + 1 = 5 points (0.6667); with the bonus 8 points (0.9412), an automatic
 *   link the bonus decided: never eligible.
 * - A3 16:00 and V4 16:07 (male, U): the same, then confirmed by a person: eligible, counted as confirmed after a bonus
 *   decision.
 *
 * Row 711 (and area "urologie"): positives U 2, negatives `kopfschmerz` 1; P = 2, N = 1, K = 2.
 * U: raw = log2((3/4) / (1/3)) = log2(2.25) = 1.1699; value = (2 · 1.1699 + 10 · 3) / 12 = 2.6950 → +3.
 * `kopfschmerz`: raw = log2((1/4) / (2/3)) = −1.4150; seed 0: value = −1.4150 / 11 = −0.1286 → 0.
 * With A2 counted, U would have 3 positives.
 */
#[ResetDatabase]
final class PlausibilityLearnerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use EncounterDataFixtures;
    use Factories;
    use MatchingDataFixtures;

    private const string U = 'urologisches_problem';

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-11-14 09:00:00');
    }

    public function testLearnsADraftFromEligiblePairsOnlyAndReportsTheReplay(): void
    {
        $seed = $this->activateSeed();
        $hospitalId = $this->day();

        $draft = $this->learner()->learn();

        self::assertSame([PlausibilitySnapshotStatus::Draft, PlausibilitySnapshotOrigin::Learned], [$draft->getStatus(), $draft->getOrigin()]);
        self::assertSame($seed->getId(), $this->repository()->activeSnapshot()?->getId(), 'the active snapshot stays');
        self::assertSame(['2026-10-12', '2026-10-12', 2], [$draft->getLearnedFrom()?->format('Y-m-d'), $draft->getLearnedTo()?->format('Y-m-d'), $draft->getEligiblePairs()]);
        self::assertSame(['smoothing' => 1.0, 'shrinkage' => 10.0, 'min_observations' => 1, 'fold' => 'alternating_days'], $draft->getLearningParameters());

        self::assertSame([
            '711|kopfschmerz +0 learned 0/1',
            '711|urologisches_problem +3 learned 2/0',
            'urologie|kopfschmerz +0 learned 0/1',
            'urologie|urologisches_problem +3 learned 2/0',
        ], $this->cellsWithCounts((int) $draft->getId()));
        self::assertSame(
            (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM encounter_plausibility_cell WHERE snapshot_id = ?', [$seed->getId()]) + 2,
            (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM encounter_plausibility_cell WHERE snapshot_id = ?', [$draft->getId()]),
            'the seed cells plus the two `kopfschmerz` cells',
        );

        $report = PlausibilityLearningReport::fromArray($draft->getReport());
        self::assertInstanceOf(PlausibilityLearningReport::class, $report);
        self::assertSame($seed->getId(), $report->activeSnapshotId);
        $figures = $report->hospitals[$hospitalId];
        self::assertSame([2, 1], [$figures['eligible_pairs'], $figures['eligible_from_bonus']]);
        // Without bonus A2 and A3 stay proposals; with the seed, the draft or the cross-fitted table (fold 0 learned on
        // fold 1, which has no data: the seed) the bonus links both. Only A3–V4 is in the reference (confirmed).
        self::assertSame(['allocations' => 3, 'automatic' => 1, 'proposal' => 2, 'none' => 0, 'decisive' => 0, 'agree' => 0, 'disagree' => 0], $figures['variants']['none']);
        foreach (['active', 'draft', 'cross_fitted'] as $variant) {
            self::assertSame(['allocations' => 3, 'automatic' => 3, 'proposal' => 0, 'none' => 0, 'decisive' => 2, 'agree' => 1, 'disagree' => 0], $figures['variants'][$variant], $variant);
        }

        $audit = $this->connection()->fetchAllAssociative(
            "SELECT action, changes::text AS changes FROM audit_log WHERE intent = 'encounter.plausibility.snapshot_learned' AND entity_class = ? ORDER BY id",
            [PlausibilitySnapshot::class],
        );
        self::assertSame(['create', 'update'], array_column($audit, 'action'), 'created, then its learning recorded');
        self::assertStringNotContainsString('eligible_from_bonus', implode(' ', array_column($audit, 'changes')), 'the report is not audited');
    }

    public function testTheBonusDecidedLinkIsNeverEligible(): void
    {
        $this->activateSeed();
        $hospitalId = $this->day();

        $pairs = self::getContainer()->get(PlausibilityLearningReaderInterface::class)->eligiblePairs(null, null);

        self::assertSame([[$hospitalId, '10:00', false], [$hospitalId, '16:00', true]], array_map(
            fn (array $pair): array => [$pair['hospital_id'], $this->arrivalOf($pair['allocation_id']), $pair['from_bonus']],
            $pairs,
        ));
    }

    public function testWithoutEligiblePairsNothingIsLearned(): void
    {
        $this->activateSeed();
        $snapshots = \count($this->repository()->snapshots());

        try {
            $this->learner()->learn();
            self::fail('Expected a refusal without eligible pairs.');
        } catch (PlausibilityException $exception) {
            self::assertSame(PlausibilityException::NO_ELIGIBLE_PAIRS, $exception->reason);
        }

        self::assertCount($snapshots, $this->repository()->snapshots());
    }

    /**
     * The fixture of the class comment; returns the hospital id.
     */
    private function day(): int
    {
        $hospital = $this->encounterHospital();
        $ivena = $this->encounterImport($hospital, 'IVENA', ImportType::ALLOCATION);
        $ed = $this->encounterImport($hospital, 'ED');
        $this->allocationAt($hospital, $ivena, '10:00', pzc: 711);
        $this->allocationAt($hospital, $ivena, '14:00', pzc: 711);
        $a3 = $this->allocationAt($hospital, $ivena, '16:00', pzc: 711);
        $this->visitAt($hospital, $ed, '10:05', diagram: self::U);
        $this->visitAt($hospital, $ed, '11:00', age: 72, diagram: 'kopfschmerz');
        $this->visitAt($hospital, $ed, '14:07', sex: Sex::Male, diagram: self::U);
        $this->visitAt($hospital, $ed, '16:07', sex: Sex::Male, diagram: self::U);

        $result = $this->matchDay($hospital);
        self::assertSame([3, 2], [$result->autoAccepted, $result->plausibilityDecisive]);
        $this->connection()->executeStatement("UPDATE encounter_match SET status = 'confirmed', decided_at = LOCALTIMESTAMP(0) WHERE allocation_id = ?", [$a3->getId()]);

        return (int) $hospital->getId();
    }

    private function learner(): PlausibilityLearner
    {
        $container = self::getContainer();
        $settings = new PlausibilityLearningSettings(smoothing: 1.0, shrinkage: 10.0, minObservations: 1);
        $lookup = new PlausibilityLookup($container->get(PlausibilityTableReaderInterface::class), $container->get(MtsCatalog::class), 1);

        return new PlausibilityLearner(
            $container->get(EntityManagerInterface::class),
            $container->get(PlausibilityLearningReaderInterface::class),
            $container->get(MatchCandidateReaderInterface::class),
            $container->get(MatchValidationReaderInterface::class),
            $container->get(PlausibilitySnapshotBuilderInterface::class),
            $lookup,
            new PlausibilityEstimator($settings),
            $settings,
            $container->get(MatchingSettings::class),
            $container->get(AuditContext::class),
            $container->get(ClockInterface::class),
        );
    }

    private function activateSeed(): PlausibilitySnapshot
    {
        $seed = $this->repository()->seedDraft();
        self::assertInstanceOf(PlausibilitySnapshot::class, $seed);
        self::getContainer()->get(PlausibilitySnapshots::class)->activate($seed);

        return $seed;
    }

    /**
     * Cells with counts of the snapshot as "pzc-or-area|diagram +points source positives/negatives".
     *
     * @return list<string>
     */
    private function cellsWithCounts(int $snapshotId): array
    {
        /** @var list<array{cell: string}> $rows */
        $rows = $this->connection()->fetchAllAssociative(
            "SELECT COALESCE(CAST(pzc AS VARCHAR), area_code) || '|' || diagram_code || ' +' || points || ' ' || source || ' ' || positives || '/' || negatives AS cell
             FROM encounter_plausibility_cell WHERE snapshot_id = ? AND positives + negatives > 0 ORDER BY pzc NULLS LAST, area_code, diagram_code",
            [$snapshotId],
        );

        return array_column($rows, 'cell');
    }

    private function arrivalOf(int $allocationId): string
    {
        return substr((string) $this->connection()->fetchOne('SELECT arrival_at FROM allocation WHERE id = ?', [$allocationId]), 11, 5);
    }

    private function repository(): PlausibilityRepositoryInterface
    {
        return self::getContainer()->get(PlausibilityRepositoryInterface::class);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
