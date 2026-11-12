<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Encounter;

use App\DataFixtures\Encounter\Application\AllocationSnapshot;
use App\DataFixtures\Encounter\Application\EncounterShares;
use App\DataFixtures\Encounter\Application\GeneratedEncounters;
use App\DataFixtures\Encounter\Application\SyntheticEncounterGenerator;
use App\DataFixtures\Encounter\Application\SyntheticVisit;
use App\DataFixtures\Encounter\Application\SyntheticVisitKind;
use App\DataFixtures\Generator\Infrastructure\Random\RandomStreams;
use App\Tests\Support\Encounter\HandMadeAllocations;
use PHPUnit\Framework\TestCase;

/**
 * Synthetic encounters of the 100 hand-made allocations of HandMadeAllocations (#1052).
 *
 * With the default shares: 85 linked visits (15 allocations without visit), 13 EMS arrivals without
 * allocation (85 × 0.15 = 12.75), 128 self-presenters (127.5), 4 unknown routes (4.25), 230 visits so
 * far and 7 repeat visits (6.9): 237 visits. Of the linked visits 4 are time outliers (4.25) and 17 have
 * an age off by 1–5 years; of all visits 12 have no ED admission (11.85) and 5 a late assessment (4.74).
 */
final class SyntheticEncounterGeneratorTest extends TestCase
{
    private const string HOSPITAL = '0193f0a6-1111-7000-8000-000000000001';

    public function testCountsPerGroupFollowTheSharesExactly(): void
    {
        $generated = $this->generate();

        self::assertCount(237, $generated->visits);
        self::assertSame([
            'linked' => 85,
            'ems_without_allocation' => 13,
            'self_presenter' => 128,
            'unknown_route' => 4,
            'repeat_visit' => 7,
        ], $generated->countsByKind());
        self::assertCount(15, $generated->allocationsWithoutVisit);
        self::assertSame(4, $generated->count(static fn (SyntheticVisit $visit): bool => $visit->timeOutlier));
        self::assertSame(17, $generated->count(static fn (SyntheticVisit $visit): bool => 0 !== $visit->ageDifference));
        self::assertSame(12, $generated->count(static fn (SyntheticVisit $visit): bool => '' === $visit->cells['ed_admission_at']));
        self::assertSame(5, $generated->count(static fn (SyntheticVisit $visit): bool => $visit->lateAssessment));
    }

    public function testEveryAllocationHasAtMostOneTrueVisit(): void
    {
        $generated = $this->generate();

        $linked = array_values($generated->trueLinks());
        self::assertCount(85, $linked);
        self::assertSame($linked, array_values(array_unique($linked)));
        $all = [...$linked, ...$generated->allocationsWithoutVisit];
        sort($all);
        self::assertSame(range(1, 100), $all, 'Every allocation is linked or listed without visit.');
        self::assertSame(0.85, \count($linked) / 100);
    }

    public function testLinkedVisitsCarryTheAllocationsFeaturesWithNoise(): void
    {
        $allocations = [];
        foreach ($this->allocations() as $allocation) {
            $allocations[$allocation->id] = $allocation;
        }

        $offsets = [];
        foreach ($this->generate()->visits as $visit) {
            if (null === $visit->allocationId) {
                continue;
            }

            $allocation = $allocations[$visit->allocationId];
            self::assertSame($allocation->age + $visit->ageDifference, (int) $visit->cells['age']);
            self::assertLessThanOrEqual(5, abs($visit->ageDifference));
            self::assertSame($allocation->gender, $visit->cells['sex']);
            self::assertSame($allocation->isWithPhysician ? 'physician_vehicle' : null, 'physician_vehicle' === $visit->cells['arrival_mode'] ? 'physician_vehicle' : null);
            if (!$allocation->isWithPhysician) {
                self::assertContains($visit->cells['arrival_mode'], ['ambulance', 'patient_transport']);
            }
            self::assertSame($allocation->isWorkAccident ? '1' : '', $visit->cells['work_accident']);
            if ('admitted' === $visit->cells['disposition']) {
                self::assertSame($allocation->departmentName, $visit->cells['admitting_department']);
            }

            $admission = $visit->edAdmissionAt();
            if (null === $admission) {
                continue; // first assessment contact only
            }

            $offset = (int) (($admission->getTimestamp() - $allocation->arrivalAt->getTimestamp()) / 60);
            $offsets[] = $offset;
            if ($visit->timeOutlier) {
                self::assertTrue(($offset >= -60 && $offset <= -21) || ($offset >= 46 && $offset <= 120), (string) $offset);
            } else {
                self::assertGreaterThanOrEqual(-5, $offset);
                self::assertLessThanOrEqual(15, $offset);
            }
        }

        // ED admission mostly a few minutes after the IVENA arrival.
        $afterArrival = \count(array_filter($offsets, static fn (int $offset): bool => $offset >= 0 && $offset <= 15));
        self::assertGreaterThan(0.75 * \count($offsets), $afterArrival);
    }

    public function testArrivalModesFollowTheDocumentedMapping(): void
    {
        $modes = [];
        foreach ($this->generate()->visits as $visit) {
            $modes[$visit->kind->value][$visit->cells['arrival_mode']] = true;
        }

        self::assertSame(['ambulance', 'patient_transport', 'physician_vehicle'], $this->sortedKeys($modes['linked']));
        self::assertEmpty(array_diff($this->sortedKeys($modes['ems_without_allocation']), ['ambulance', 'patient_transport', 'physician_vehicle']));
        self::assertSame(['self'], $this->sortedKeys($modes['self_presenter']));
        self::assertSame(['self'], $this->sortedKeys($modes['repeat_visit']));
        self::assertEmpty(array_diff($this->sortedKeys($modes['unknown_route']), ['', 'other']));
    }

    public function testRepeatVisitsShareTheCaseNumberOfAnEarlierVisitHoursLater(): void
    {
        $byCase = [];
        foreach ($this->generate()->visits as $visit) {
            $byCase[$visit->caseNumber][] = $visit;
        }

        $repeated = array_filter($byCase, static fn (array $visits): bool => \count($visits) > 1);
        self::assertCount(7, $repeated, 'Seven case numbers with two visits.');
        foreach ($repeated as $visits) {
            self::assertCount(2, $visits);
            self::assertSame(SyntheticVisitKind::RepeatVisit, $visits[1]->kind);
            $hours = ($visits[1]->edAdmissionAt() ?? $visits[1]->firstAssessmentContactAt())->getTimestamp() - $visits[0]->matchingAnchor()->getTimestamp();
            self::assertGreaterThanOrEqual(2 * 3600, $hours, 'Far outside the 15-minute window of suspected duplicates.');
        }
    }

    public function testRowsWithoutAdmissionAndLateAssessmentsKeepAnAnchor(): void
    {
        foreach ($this->generate()->visits as $visit) {
            self::assertNotSame('', $visit->cells['first_assessment_contact_at']);
            if ($visit->lateAssessment) {
                $before = $visit->cellsAsExported(false);
                self::assertSame('', $before['first_assessment_contact_at']);
                self::assertSame('', $before['mts_category']);
                self::assertNotSame('', $before['ed_admission_at']);
                $minutes = ($visit->firstAssessmentContactAt()?->getTimestamp() - $visit->edAdmissionAt()?->getTimestamp()) / 60;
                self::assertGreaterThanOrEqual(1, $minutes);
                self::assertLessThanOrEqual(15, $minutes, 'The later fingerprint stays within the suspected-duplicate window.');
            }
        }
    }

    public function testSameSeedGivesTheSameVisitsWhateverTheOrderOfAllocations(): void
    {
        $allocations = $this->allocations();
        $reversed = array_reverse($allocations);

        self::assertSame($this->fingerprint($this->generate($allocations)), $this->fingerprint($this->generate($reversed)));
        self::assertNotSame($this->fingerprint($this->generate($allocations)), $this->fingerprint($this->generate($allocations, seed: 741)));
    }

    public function testCustomSharesAndNoAllocations(): void
    {
        $generated = $this->generate([], shares: new EncounterShares(selfPresenters: 2.0));
        self::assertSame([], $generated->visits, 'Other groups are sized by the linked visits.');

        $generated = $this->generate(shares: new EncounterShares(linked: 1.0, emsWithoutAllocation: 0.0, selfPresenters: 0.0, unknownRoute: 0.0, repeatVisits: 0.0, timeOutliers: 0.0, ageNoise: 0.0, missingAdmission: 0.0, lateAssessment: 0.0));
        self::assertSame(['linked' => 100, 'ems_without_allocation' => 0, 'self_presenter' => 0, 'unknown_route' => 0, 'repeat_visit' => 0], $generated->countsByKind());
        self::assertSame(0, $generated->count(static fn (SyntheticVisit $visit): bool => 0 !== $visit->ageDifference || $visit->timeOutlier));
    }

    public function testInvalidSharesAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EncounterShares(linked: 1.2);
    }

    /**
     * @return list<AllocationSnapshot>
     */
    private function allocations(): array
    {
        return HandMadeAllocations::october2026();
    }

    /**
     * @param list<AllocationSnapshot>|null $allocations
     */
    private function generate(?array $allocations = null, int $seed = 740, EncounterShares $shares = new EncounterShares()): GeneratedEncounters
    {
        return new SyntheticEncounterGenerator(new RandomStreams())->generate(
            $seed,
            self::HOSPITAL,
            new \DateTimeImmutable('2026-10-01'),
            new \DateTimeImmutable('2026-10-31'),
            $allocations ?? $this->allocations(),
            $shares,
        );
    }

    private function fingerprint(GeneratedEncounters $generated): string
    {
        return hash('sha256', serialize(array_map(static fn (SyntheticVisit $visit): array => [$visit->caseNumber, $visit->kind->value, $visit->allocationId, $visit->cells, $visit->lateAssessment], $generated->visits)));
    }

    /**
     * @param array<string, true> $set
     *
     * @return list<string>
     */
    private function sortedKeys(array $set): array
    {
        $keys = array_map(strval(...), array_keys($set));
        sort($keys);

        return $keys;
    }
}
