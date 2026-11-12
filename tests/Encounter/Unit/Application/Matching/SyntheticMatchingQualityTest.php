<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\Matching;

use App\DataFixtures\Encounter\Application\AllocationSnapshot;
use App\DataFixtures\Encounter\Application\SyntheticEncounterGenerator;
use App\DataFixtures\Generator\Infrastructure\Random\RandomStreams;
use App\Encounter\Application\Matching\MatchingAllocation;
use App\Encounter\Application\Matching\MatchingEncounter;
use App\Encounter\Application\Matching\MatchingSettings;
use App\Encounter\Application\Matching\MatchPlanner;
use App\Encounter\Domain\Enum\ArrivalMode;
use App\Encounter\Domain\Enum\ArrivalRoute;
use App\Encounter\Domain\Enum\MatchStatus;
use App\Encounter\Domain\Enum\Sex;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Guard rail on synthetic visits with known truth (#1052): 30 allocations a day in October 2026 and the
 * visits the generator makes for them. The bounds lie well below what the matcher reached on such data
 * when it was written (precision of automatic links about 0.99–1.00, recall about 0.75–0.80, reported in
 * the #1053 pull request); they catch a broken matcher and say nothing about real data.
 */
final class SyntheticMatchingQualityTest extends TestCase
{
    private const int SEED = 1;

    public function testAutomaticLinksOnSyntheticDataAreAlmostAlwaysRight(): void
    {
        [$allocations, $encounters, $truth] = $this->syntheticMonth(30);

        $plan = new MatchPlanner(new MatchingSettings())->plan($allocations, $encounters);

        $links = array_values(array_filter($plan, static fn (\App\Encounter\Application\Matching\PlannedMatch $match): bool => MatchStatus::AutoAccepted === $match->status));
        $correct = \count(array_filter($links, static fn (\App\Encounter\Application\Matching\PlannedMatch $match): bool => $truth[$match->encounterId] === $match->allocationId));
        $truePairs = \count(array_filter($truth, static fn (?int $allocationId): bool => null !== $allocationId));

        self::assertGreaterThan(500, \count($links));
        self::assertGreaterThanOrEqual(0.98, $correct / \count($links), 'precision of automatic links');
        self::assertGreaterThanOrEqual(0.70, $correct / $truePairs, 'recall of automatic links');
        self::assertCount(\count($links), array_unique(array_map(static fn (\App\Encounter\Application\Matching\PlannedMatch $match): int => $match->encounterId, $links)));
        self::assertCount(\count($links), array_unique(array_map(static fn (\App\Encounter\Application\Matching\PlannedMatch $match): int => $match->allocationId, $links)));
    }

    /**
     * @return array{0: list<MatchingAllocation>, 1: list<MatchingEncounter>, 2: array<int, ?int>}
     */
    private function syntheticMonth(int $perDay): array
    {
        $random = new Randomizer(new Mt19937(self::SEED));
        $snapshots = [];
        for ($day = 0; $day < 31; ++$day) {
            for ($i = 0; $i < $perDay; ++$i) {
                $snapshots[] = new AllocationSnapshot(
                    id: \count($snapshots) + 1,
                    arrivalAt: new \DateTimeImmutable('2026-10-01')->modify(sprintf('+%d days', $day))->setTime($random->getInt(0, 23), $random->getInt(0, 59)),
                    age: $random->getInt(1, 99),
                    gender: 0 === $random->getInt(0, 1) ? 'M' : 'F',
                    urgency: $random->getInt(1, 3),
                    isWithPhysician: $random->getInt(1, 100) <= 15,
                    isWorkAccident: $random->getInt(1, 100) <= 5,
                );
            }
        }

        $generated = new SyntheticEncounterGenerator(new RandomStreams())->generate(self::SEED, 'quality-check', new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'), $snapshots);

        $encounters = [];
        $truth = [];
        foreach ($generated->visits as $index => $visit) {
            $id = 100_000 + $index;
            $anchor = MatchingEncounter::anchorOf($visit->edAdmissionAt(), $visit->firstAssessmentContactAt());
            self::assertNotNull($anchor);
            $mode = ArrivalMode::tryFrom($visit->cells['arrival_mode']);
            $encounters[] = new MatchingEncounter($id, $anchor[0], $anchor[1], Sex::from($visit->cells['sex']), (int) $visit->cells['age'], $mode, ArrivalRoute::fromArrivalMode($mode), '1' === $visit->cells['work_accident'] ? true : null);
            $truth[$id] = $visit->allocationId;
        }

        $allocations = array_map(static fn (AllocationSnapshot $a): MatchingAllocation => new MatchingAllocation($a->id, $a->arrivalAt, $a->gender, $a->age, $a->isWithPhysician, $a->isWorkAccident), $snapshots);

        return [$allocations, $encounters, $truth];
    }
}
