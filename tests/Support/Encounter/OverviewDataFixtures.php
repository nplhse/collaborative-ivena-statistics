<?php

declare(strict_types=1);

namespace App\Tests\Support\Encounter;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Domain\Entity\Encounter;
use App\Encounter\Domain\Entity\EncounterDisposition;
use App\Encounter\Domain\Entity\EncounterMatch;
use App\Encounter\Domain\Entity\EncounterTimes;
use App\Encounter\Domain\Entity\Triage;
use App\Encounter\Domain\Enum\AnchorSource;
use App\Encounter\Domain\Enum\ArrivalMode;
use App\Encounter\Domain\Enum\CareLevel;
use App\Encounter\Domain\Enum\Disposition;
use App\Encounter\Domain\Enum\MatchStatus;
use App\Encounter\Domain\Enum\MtsCategory;
use App\Encounter\Domain\Enum\Sex;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use App\User\Domain\Entity\User;

/**
 * Hand-checkable data of the encounter overview (#1055). Period under test: 10–12 October 2026.
 *
 * | Visit | Anchor (day) | Admission → assess / physician / discharge (min) | Route, link     | MTS               | Disposition, care |
 * |-------|--------------|---------------------------------------------------|-----------------|-------------------|-------------------|
 * | V1    | 11 Oct       | 5 / 20 / 120                                      | EMS, automatic  | red thoraxschmerz | admitted, icu     |
 * | V2    | 11 Oct       | 10 / 40 / 180                                     | EMS, confirmed  | orange thoraxschm.| admitted, ward    |
 * | V3    | 11 Oct       | 15 / 60 / 240                                     | EMS, proposal   | orange thoraxschm.| transferred, –    |
 * | V4    | 11 Oct       | 20 / 120 / 1560 (implausible)                     | EMS (NEF), auto | yellow kopfschmerz| admitted, monitor |
 * | V5    | 11 Oct       | 25 / −10 (negative) / 0                            | self, –         | green kopfschmerz | home              |
 * | V6    | 11 Oct       | no admission: all missing                         | self, rejected  | green wunden      | left before end   |
 * | V7    | 11 Oct       | 30 / 45 / 1440 (exactly 24 h, counted)            | unknown (other) | blue –            | home              |
 * | B2    | 10 Oct 00:00 | no admission                                      | unknown (empty) | – –               | –                 |
 * | B3    | 12 Oct 23:59 | no admission                                      | EMS (KTW), auto | yellow –          | died              |
 * | B1    | 9 Oct 23:59  | outside the period                                | EMS             | red               | home              |
 * | B4    | 13 Oct 00:00 | outside the period                                | EMS             | red               | home              |
 *
 * B2 and B3 belong to a second import. The other hospital has 6 red EMS visits on 11 October with
 * an automatic link each; they must never be counted.
 *
 * Needs EncounterDataFixtures, MatchingDataFixtures, a booted kernel and Foundry's Factories trait.
 */
trait OverviewDataFixtures
{
    private int $overviewVisit = 0;

    /**
     * @return array<string, int> visit label => encounter id
     */
    private function loadOverviewData(Hospital $hospital, Hospital $otherHospital, User $decider): array
    {
        $main = $this->encounterImport($hospital, 'Overview main');
        $second = $this->encounterImport($hospital, 'Overview second');
        $allocations = $this->encounterImport($hospital, 'Overview allocations', ImportType::ALLOCATION);

        $day = '2026-10-11 ';
        $ids = [];
        $ids['V1'] = $this->overviewVisit($hospital, $main, $day.'10:00', $day.'10:05', $day.'10:20', $day.'12:00', ArrivalMode::Ambulance, MtsCategory::Red, 'thoraxschmerz', Disposition::Admitted, CareLevel::Icu);
        $ids['V2'] = $this->overviewVisit($hospital, $main, $day.'11:00', $day.'11:10', $day.'11:40', $day.'14:00', ArrivalMode::Ambulance, MtsCategory::Orange, 'thoraxschmerz', Disposition::Admitted, CareLevel::Ward);
        $ids['V3'] = $this->overviewVisit($hospital, $main, $day.'12:00', $day.'12:15', $day.'13:00', $day.'16:00', ArrivalMode::Ambulance, MtsCategory::Orange, 'thoraxschmerz', Disposition::TransferredExternal, null);
        $ids['V4'] = $this->overviewVisit($hospital, $main, $day.'13:00', $day.'13:20', $day.'15:00', '2026-10-12 15:00', ArrivalMode::PhysicianVehicle, MtsCategory::Yellow, 'kopfschmerz', Disposition::Admitted, CareLevel::Monitoring);
        $ids['V5'] = $this->overviewVisit($hospital, $main, $day.'14:00', $day.'14:25', $day.'13:50', $day.'14:00', ArrivalMode::Self, MtsCategory::Green, 'kopfschmerz', Disposition::Home, null);
        $ids['V6'] = $this->overviewVisit($hospital, $main, null, $day.'15:00', null, null, ArrivalMode::Self, MtsCategory::Green, 'wunden', Disposition::LeftBeforeCompletion, null);
        $ids['V7'] = $this->overviewVisit($hospital, $main, $day.'16:00', $day.'16:30', $day.'16:45', '2026-10-12 16:00', ArrivalMode::Other, MtsCategory::Blue, null, Disposition::Home, null);
        $ids['B2'] = $this->overviewVisit($hospital, $second, null, '2026-10-10 00:00', null, null, null, null, null, null, null);
        $ids['B3'] = $this->overviewVisit($hospital, $second, null, '2026-10-12 23:59', null, null, ArrivalMode::PatientTransport, MtsCategory::Yellow, null, Disposition::Died, null);
        $ids['B1'] = $this->overviewVisit($hospital, $main, null, '2026-10-09 23:59', null, null, ArrivalMode::Ambulance, MtsCategory::Red, null, Disposition::Home, null);
        $ids['B4'] = $this->overviewVisit($hospital, $main, null, '2026-10-13 00:00', null, null, ArrivalMode::Ambulance, MtsCategory::Red, null, Disposition::Home, null);

        $this->link($allocations, $ids['V1'], '10:00', MatchStatus::AutoAccepted, $decider);
        $this->link($allocations, $ids['V2'], '11:00', MatchStatus::Confirmed, $decider);
        $this->link($allocations, $ids['V3'], '12:00', MatchStatus::Proposed, $decider);
        $this->link($allocations, $ids['V4'], '13:00', MatchStatus::AutoAccepted, $decider);
        $this->link($allocations, $ids['V6'], '15:00', MatchStatus::Rejected, $decider);
        $this->link($allocations, $ids['B3'], '23:50', MatchStatus::AutoAccepted, $decider);

        $otherImport = $this->encounterImport($otherHospital, 'Other hospital');
        $otherAllocations = $this->encounterImport($otherHospital, 'Other allocations', ImportType::ALLOCATION);
        for ($i = 0; $i < 6; ++$i) {
            $time = sprintf('%02d:00', 8 + $i);
            $id = $this->overviewVisit($otherHospital, $otherImport, $day.$time, $day.$time, $day.$time, $day.$time, ArrivalMode::Ambulance, MtsCategory::Red, 'thoraxschmerz', Disposition::Admitted, CareLevel::Icu);
            $this->link($otherAllocations, $id, $time, MatchStatus::AutoAccepted, $decider);
        }

        $this->encounterEntityManager()->flush();
        $this->encounterEntityManager()->clear();

        return $ids;
    }

    private function overviewVisit(
        Hospital $hospital,
        Import $import,
        ?string $admission,
        ?string $assessment,
        ?string $physician,
        ?string $discharge,
        ?ArrivalMode $mode,
        ?MtsCategory $category,
        ?string $diagram,
        ?Disposition $disposition,
        ?CareLevel $careLevel,
    ): int {
        $at = static fn (?string $time): ?\DateTimeImmutable => null === $time ? null : new \DateTimeImmutable($time);
        ++$this->overviewVisit;
        $id = $this->encounterRepository()->insertIfNewReturningId(new Encounter(
            hospital: $hospital,
            import: $import,
            keyGeneration: 1,
            visitFingerprint: hash('sha256', 'overview|'.$this->overviewVisit),
            caseKey: hash('sha256', 'overview-case|'.$this->overviewVisit),
            age: 70,
            sex: Sex::Female,
            times: new EncounterTimes(
                edAdmissionAt: $at($admission),
                firstAssessmentContactAt: $at($assessment),
                firstPhysicianAt: $at($physician),
                edDischargeAt: $at($discharge),
            ),
            arrivalMode: $mode,
            triage: new Triage($category, $diagram),
            disposition: new EncounterDisposition($disposition, $careLevel),
        ));
        self::assertNotNull($id);

        return $id;
    }

    /**
     * Pairs the visit with a new allocation of the same hospital arriving at $arrival on 11 October.
     * Confirmed and rejected pairs start as proposals and get the decision, as in the review.
     */
    private function link(Import $allocationImport, int $encounterId, string $arrival, MatchStatus $status, User $decider): Allocation
    {
        $em = $this->encounterEntityManager();
        $encounter = $em->find(Encounter::class, $encounterId);
        self::assertInstanceOf(Encounter::class, $encounter);
        $hospital = $encounter->getHospital();

        $allocation = $this->allocationAt($hospital, $allocationImport, $arrival);
        $allocation = $em->find(Allocation::class, $allocation->getId());
        self::assertInstanceOf(Allocation::class, $allocation);

        $initial = MatchStatus::AutoAccepted === $status ? MatchStatus::AutoAccepted : MatchStatus::Proposed;
        $match = new EncounterMatch($allocation, $encounter, $initial, AnchorSource::Admission, 7, new \DateTimeImmutable('2026-10-12 09:00'), 0.9697, 9, ['time' => 4, 'sex' => 2, 'age' => 2, 'arrival_route' => 1], 1, '1.0.0');
        if ($status->isManual()) {
            $match->decide($status, $em->getReference(User::class, (int) $decider->getId()), new \DateTimeImmutable('2026-10-12 10:00'));
        }

        $em->persist($match);
        $em->flush();

        return $allocation;
    }
}
