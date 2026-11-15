<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\CaseCard;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\CaseCard\HospitalCourseFactory;
use App\Encounter\Application\CaseCard\IntervalValue;
use App\Encounter\Application\CaseCard\TimelineEvent;
use App\Encounter\Domain\Entity\Encounter;
use App\Encounter\Domain\Entity\EncounterMatch;
use App\Encounter\Domain\Entity\EncounterTimes;
use App\Encounter\Domain\Entity\InitialVitals;
use App\Encounter\Domain\Enum\AnchorSource;
use App\Encounter\Domain\Enum\MatchStatus;
use App\Encounter\Domain\Enum\Sex;
use App\Encounter\Domain\Enum\VitalsReference;
use App\Import\Domain\Entity\Import;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Case card "Hospital course" (#1055). IVENA arrival 10:00, ED admission 10:07, first assessment
 * 10:12, first physician 10:40, ED discharge 13:07.
 */
final class HospitalCourseFactoryTest extends TestCase
{
    private Hospital $hospital;

    #[\Override]
    protected function setUp(): void
    {
        $this->hospital = new Hospital();
    }

    public function testTimelineIsOrderedAndRelativeToTheEdAdmission(): void
    {
        $course = new HospitalCourseFactory()->create($this->link(new InitialVitals(VitalsReference::AdmissionMeasurement, new \DateTimeImmutable('2026-10-12 10:09'), heartRate: 88)));

        self::assertSame(
            ['ivena_arrival 10:00 -7', 'ed_admission 10:07 0', 'vitals_measured 10:09 2', 'first_assessment 10:12 5', 'first_physician 10:40 33', 'ed_discharge 13:07 180'],
            array_map(static fn (TimelineEvent $event): string => sprintf('%s %s %s', $event->key, $event->at->format('H:i'), $event->minutesFromAdmission), $course->timeline),
        );
        self::assertTrue($course->vitalsOnTimeline);
        self::assertSame([5, 33, 180], array_map(static fn (IntervalValue $value): ?int => $value->minutes, $course->intervals));
        self::assertTrue($course->isAutomatic());
        self::assertEqualsWithDelta(96.97, $course->agreementPercent(), 0.0001);
    }

    public function testDocumentedInitialVitalsAreNeverOnTheTimeline(): void
    {
        $course = new HospitalCourseFactory()->create($this->link(new InitialVitals(VitalsReference::DocumentedInitial, heartRate: 88)));

        self::assertFalse($course->vitalsOnTimeline);
        self::assertNotContains('vitals_measured', array_map(static fn (TimelineEvent $event): string => $event->key, $course->timeline));
    }

    public function testMissingAdmissionLeavesIntervalsEmptyAndConfirmedLinksShowTheirOrigin(): void
    {
        $course = new HospitalCourseFactory()->create($this->link(new InitialVitals(), admission: null, status: MatchStatus::Confirmed));

        self::assertSame([null, null, null], array_map(static fn (IntervalValue $value): ?int => $value->minutes, $course->intervals));
        self::assertNull($course->timeline[1]->minutesFromAdmission, 'first assessment without admission');
        self::assertFalse($course->isAutomatic());
    }

    public function testImplausibleDurationsAreFlagged(): void
    {
        $course = new HospitalCourseFactory()->create($this->link(new InitialVitals(), discharge: '2026-10-13 10:08'));

        self::assertSame(1441, $course->intervals[2]->minutes);
        self::assertFalse($course->intervals[2]->plausible, 'More than 24 hours.');
        self::assertTrue($course->intervals[0]->plausible);
    }

    public function testVisitWithoutLinkHasNoIvenaTimeAndTheSameDurations(): void
    {
        $encounter = $this->link(new InitialVitals(VitalsReference::AdmissionMeasurement, new \DateTimeImmutable('2026-10-12 10:09'), heartRate: 88))->getEncounter();

        $course = new HospitalCourseFactory()->forEncounter($encounter, null);

        self::assertSame(
            ['ed_admission 10:07 0', 'vitals_measured 10:09 2', 'first_assessment 10:12 5', 'first_physician 10:40 33', 'ed_discharge 13:07 180'],
            array_map(static fn (TimelineEvent $event): string => sprintf('%s %s %s', $event->key, $event->at->format('H:i'), $event->minutesFromAdmission), $course->timeline),
        );
        self::assertSame([5, 33, 180], array_map(static fn (IntervalValue $value): ?int => $value->minutes, $course->intervals));
        self::assertNull($course->link);
        self::assertFalse($course->isAutomatic());
        self::assertNull($course->agreementPercent());
    }

    public function testVisitWithOnlyAFirstAssessmentHasOneEventAndNoDurations(): void
    {
        $encounter = new Encounter(
            hospital: $this->hospital,
            import: new Import(),
            keyGeneration: 1,
            visitFingerprint: hash('sha256', 'minimal'),
            caseKey: hash('sha256', 'minimal-case'),
            age: 47,
            sex: Sex::Male,
            times: new EncounterTimes(firstAssessmentContactAt: new \DateTimeImmutable('2026-10-12 13:31')),
        );

        $course = new HospitalCourseFactory()->forEncounter($encounter, null);

        self::assertSame(['first_assessment'], array_map(static fn (TimelineEvent $event): string => $event->key, $course->timeline));
        self::assertNull($course->timeline[0]->minutesFromAdmission);
        self::assertSame([null, null, null], array_map(static fn (IntervalValue $value): ?int => $value->minutes, $course->intervals));
        self::assertFalse($course->vitalsOnTimeline);
    }

    public function testLinkOfAnotherVisitIsRejected(): void
    {
        $link = $this->link(new InitialVitals());
        $other = $this->link(new InitialVitals())->getEncounter();

        $this->expectException(\InvalidArgumentException::class);
        new HospitalCourseFactory()->forEncounter($other, $link);
    }

    private function link(InitialVitals $vitals, ?string $admission = '2026-10-12 10:07', string $discharge = '2026-10-12 13:07', MatchStatus $status = MatchStatus::AutoAccepted): EncounterMatch
    {
        $encounter = new Encounter(
            hospital: $this->hospital,
            import: new Import(),
            keyGeneration: 1,
            visitFingerprint: hash('sha256', 'visit'),
            caseKey: hash('sha256', 'case'),
            age: 70,
            sex: Sex::Female,
            times: new EncounterTimes(
                edAdmissionAt: null === $admission ? null : new \DateTimeImmutable($admission),
                firstAssessmentContactAt: new \DateTimeImmutable('2026-10-12 10:12'),
                firstPhysicianAt: new \DateTimeImmutable('2026-10-12 10:40'),
                edDischargeAt: new \DateTimeImmutable($discharge),
            ),
            vitals: $vitals,
        );
        $allocation = new Allocation()->setHospital($this->hospital)->setArrivalAt(new \DateTimeImmutable('2026-10-12 10:00'));
        $initial = MatchStatus::AutoAccepted === $status ? $status : MatchStatus::Proposed;
        $match = new EncounterMatch($allocation, $encounter, $initial, AnchorSource::Admission, 7, new \DateTimeImmutable('2026-10-12 11:00'), score: 0.9697);
        if ($status->isManual()) {
            $match->decide($status, new User(), new \DateTimeImmutable('2026-10-12 12:00'));
        }

        return $match;
    }
}
