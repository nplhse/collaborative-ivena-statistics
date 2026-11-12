<?php

declare(strict_types=1);

namespace App\Tests\Support\Encounter;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Encounter\Application\Matching\MatchRunResult;
use App\Encounter\Application\Matching\MatchScope;
use App\Encounter\Domain\Entity\Encounter;
use App\Encounter\Domain\Entity\EncounterTimes;
use App\Encounter\Domain\Enum\ArrivalMode;
use App\Encounter\Domain\Enum\Sex;
use App\Encounter\Infrastructure\Matching\EncounterMatcher;
use App\Import\Domain\Entity\Import;

/**
 * Hand-checkable allocations and visits for matcher DB tests on 12 October 2026. Defaults as in the unit
 * tests: female, 70 years, ambulance, no physician, no work accident (9 points at the arrival time).
 * Needs EncounterDataFixtures, a booted kernel and Foundry's Factories trait.
 */
trait MatchingDataFixtures
{
    private function allocationAt(Hospital $hospital, Import $import, string $arrival, AllocationGender $gender = AllocationGender::FEMALE, int $age = 70): Allocation
    {
        $arrivalAt = new \DateTimeImmutable('2026-10-12 '.$arrival);

        return AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'dispatchArea' => $hospital->getDispatchArea(),
            'state' => $hospital->getState(),
            'createdAt' => $arrivalAt->modify('-30 minutes'),
            'arrivalAt' => $arrivalAt,
            'gender' => $gender,
            'age' => $age,
            'isWithPhysician' => false,
            'isWorkAccident' => false,
            'speciality' => SpecialityFactory::createOne(),
            'department' => DepartmentFactory::createOne(),
            'assignment' => AssignmentFactory::createOne(),
            'indicationRaw' => IndicationRawFactory::createOne(),
            'indicationNormalized' => null,
            'infection' => null,
            'occasion' => null,
            'secondaryTransport' => null,
            'assessment' => null,
        ]);
    }

    /**
     * Visit with ED admission at $admission (or only a first assessment contact at $assessment).
     */
    private function visitAt(Hospital $hospital, Import $import, ?string $admission, ?string $assessment = null, Sex $sex = Sex::Female, int $age = 70, ArrivalMode $mode = ArrivalMode::Ambulance): int
    {
        $at = static fn (?string $time): ?\DateTimeImmutable => null === $time ? null : new \DateTimeImmutable('2026-10-12 '.$time);
        $id = $this->encounterRepository()->insertIfNewReturningId(new Encounter(
            hospital: $hospital,
            import: $import,
            keyGeneration: 1,
            visitFingerprint: hash('sha256', 'visit|'.$admission.'|'.$assessment.'|'.$sex->value.$age.'|'.$mode->value),
            caseKey: hash('sha256', 'case|'.$admission.'|'.$assessment.'|'.$sex->value.$age),
            age: $age,
            sex: $sex,
            times: new EncounterTimes(edAdmissionAt: $at($admission), firstAssessmentContactAt: $at($assessment)),
            arrivalMode: $mode,
        ));
        self::assertNotNull($id);

        return $id;
    }

    private function matchDay(Hospital $hospital, string $day = '2026-10-12'): MatchRunResult
    {
        $matcher = self::getContainer()->get(EncounterMatcher::class);
        self::assertInstanceOf(EncounterMatcher::class, $matcher);

        return $matcher->run(new MatchScope((int) $hospital->getId(), [$day]));
    }

    /**
     * Rows of the hospital as "allocationId-encounterId status score [conflict]", ordered by pair.
     *
     * @return list<string>
     */
    private function matchRows(Hospital $hospital): array
    {
        /** @var list<array{allocation_id: int, encounter_id: int, status: string, score: ?float, conflict: bool}> $rows */
        $rows = $this->encounterEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT allocation_id, encounter_id, status, score, conflict FROM encounter_match WHERE hospital_id = ? ORDER BY allocation_id, encounter_id',
            [$hospital->getId()],
        );

        return array_map(static fn (array $row): string => sprintf(
            '%d-%d %s %s%s',
            $row['allocation_id'],
            $row['encounter_id'],
            $row['status'],
            null === $row['score'] ? '-' : number_format((float) $row['score'], 4, '.', ''),
            $row['conflict'] ? ' conflict' : '',
        ), $rows);
    }
}
