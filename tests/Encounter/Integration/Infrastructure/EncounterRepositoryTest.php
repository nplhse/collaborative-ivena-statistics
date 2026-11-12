<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Infrastructure;

use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Encounter\Domain\Entity\Encounter;
use App\Encounter\Domain\Entity\EncounterDisposition;
use App\Encounter\Domain\Entity\EncounterTimes;
use App\Encounter\Domain\Entity\InitialVitals;
use App\Encounter\Domain\Entity\Retriage;
use App\Encounter\Domain\Entity\Triage;
use App\Encounter\Domain\Enum\AdmissionType;
use App\Encounter\Domain\Enum\AnchorSource;
use App\Encounter\Domain\Enum\ArrivalMode;
use App\Encounter\Domain\Enum\ArrivalRoute;
use App\Encounter\Domain\Enum\CareLevel;
use App\Encounter\Domain\Enum\CaseStatus;
use App\Encounter\Domain\Enum\Disposition;
use App\Encounter\Domain\Enum\EncounterInsertOutcome;
use App\Encounter\Domain\Enum\IsolationCode;
use App\Encounter\Domain\Enum\MtsCategory;
use App\Encounter\Domain\Enum\ReferralSource;
use App\Encounter\Domain\Enum\Sex;
use App\Encounter\Domain\Enum\VitalsReference;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class EncounterRepositoryTest extends KernelTestCase
{
    use EncounterDataFixtures;
    use Factories;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testInsertStoresEveryFieldOfTheVisit(): void
    {
        $hospital = $this->encounterHospital();
        $import = $this->encounterImport($hospital, 'A');
        $department = DepartmentFactory::createOne(['name' => 'Internal medicine']);
        $at = static fn (string $time): \DateTimeImmutable => new \DateTimeImmutable('2026-10-12 '.$time);

        $outcome = $this->encounterRepository()->insertIfNew(new Encounter(
            hospital: $hospital,
            import: $import,
            keyGeneration: 2,
            visitFingerprint: self::fingerprintFor('2026-10-12'),
            caseKey: hash('sha256', 'case|full'),
            age: 81,
            sex: Sex::Diverse,
            times: new EncounterTimes(
                edAdmissionAt: $at('10:02'),
                firstAssessmentContactAt: $at('10:05'),
                assessmentStartAt: $at('10:06'),
                assessmentEndAt: $at('10:09'),
                physicianTargetAt: $at('10:15'),
                firstPhysicianAt: $at('10:14'),
                treatmentEndAt: $at('13:40'),
                edDischargeAt: $at('13:55'),
                adminAdmissionAt: $at('10:20'),
                adminDischargeAt: new \DateTimeImmutable('2026-10-19 11:00'),
                preInpatientAdmissionAt: null,
            ),
            arrivalMode: ArrivalMode::PhysicianVehicle,
            referralSource: ReferralSource::EmergencyPhysician,
            admissionType: AdmissionType::Emergency,
            caseStatus: CaseStatus::Inpatient,
            triage: new Triage(MtsCategory::Orange, 'chest_pain', 'cardiac_pain', 7),
            retriage: new Retriage(MtsCategory::Yellow, 'chest_pain', 'pleuritic_pain', $at('11:30')),
            disposition: new EncounterDisposition(Disposition::Admitted, CareLevel::Icu),
            admittingDepartment: $department,
            vitals: new InitialVitals(VitalsReference::AdmissionMeasurement, $at('10:07'), 165, 95, 112, '38.4', 24, 130, 91, 14),
            workAccident: true,
            tetanusProtected: true,
            isolation: [IsolationCode::Mrsa, IsolationCode::Covid19, IsolationCode::Mrsa],
        ));

        self::assertSame(EncounterInsertOutcome::Inserted, $outcome);

        $em = $this->encounterEntityManager();
        $em->clear();
        $visit = $em->getRepository(Encounter::class)->findOneBy(['visitFingerprint' => self::fingerprintFor('2026-10-12')]);
        self::assertInstanceOf(Encounter::class, $visit);

        self::assertNotNull($visit->getId());
        self::assertSame(self::fingerprintFor('2026-10-12'), $visit->getVisitFingerprint());
        self::assertInstanceOf(\DateTimeImmutable::class, $visit->getCreatedAt());
        self::assertSame($hospital->getId(), $visit->getHospital()->getId());
        self::assertSame($import->getId(), $visit->getImport()->getId());
        self::assertSame(2, $visit->getKeyGeneration());
        self::assertSame(hash('sha256', 'case|full'), $visit->getCaseKey());
        self::assertSame(AnchorSource::Assessment, $visit->getAnchorSource());
        self::assertEquals($at('10:05'), $visit->getAnchorAt());
        self::assertSame(81, $visit->getAge());
        self::assertSame(Sex::Diverse, $visit->getSex());
        self::assertSame(ArrivalMode::PhysicianVehicle, $visit->getArrivalMode());
        self::assertSame(ArrivalRoute::Ems, $visit->getArrivalRoute());
        self::assertSame(ReferralSource::EmergencyPhysician, $visit->getReferralSource());
        self::assertSame(AdmissionType::Emergency, $visit->getAdmissionType());
        self::assertSame(CaseStatus::Inpatient, $visit->getCaseStatus());

        $times = $visit->getTimes();
        self::assertEquals($at('10:02'), $times->getEdAdmissionAt());
        self::assertEquals($at('10:05'), $times->getFirstAssessmentContactAt());
        self::assertEquals($at('10:06'), $times->getAssessmentStartAt());
        self::assertEquals($at('10:09'), $times->getAssessmentEndAt());
        self::assertEquals($at('10:15'), $times->getPhysicianTargetAt());
        self::assertEquals($at('10:14'), $times->getFirstPhysicianAt());
        self::assertEquals($at('13:40'), $times->getTreatmentEndAt());
        self::assertEquals($at('10:20'), $times->getAdminAdmissionAt());
        self::assertEquals($at('13:55'), $times->getEdDischargeAt());
        self::assertEquals(new \DateTimeImmutable('2026-10-19 11:00'), $times->getAdminDischargeAt());
        self::assertNull($times->getPreInpatientAdmissionAt());

        self::assertSame(MtsCategory::Orange, $visit->getTriage()->getCategory());
        self::assertSame('chest_pain', $visit->getTriage()->getDiagram());
        self::assertSame('cardiac_pain', $visit->getTriage()->getIndicator());
        self::assertSame(7, $visit->getTriage()->getPainScore());
        self::assertSame(MtsCategory::Yellow, $visit->getRetriage()->getCategory());
        self::assertSame('chest_pain', $visit->getRetriage()->getDiagram());
        self::assertSame('pleuritic_pain', $visit->getRetriage()->getIndicator());
        self::assertEquals($at('11:30'), $visit->getRetriage()->getAt());

        self::assertTrue($visit->getDisposition()->isAdmission());
        self::assertSame(CareLevel::Icu, $visit->getDisposition()->getCareLevel());
        self::assertSame($department->getId(), $visit->getAdmittingDepartment()?->getId());

        $vitals = $visit->getVitals();
        self::assertSame(VitalsReference::AdmissionMeasurement, $vitals->getReference());
        self::assertEquals($at('10:07'), $vitals->getMeasuredAt());
        self::assertSame([165, 95, 112, '38.4', 24, 130, 91, 14], [
            $vitals->getBpSystolic(), $vitals->getBpDiastolic(), $vitals->getHeartRate(), $vitals->getTemperature(),
            $vitals->getRespiratoryRate(), $vitals->getGlucose(), $vitals->getSpo2(), $vitals->getGcs(),
        ]);

        self::assertTrue($visit->getWorkAccident());
        self::assertTrue($visit->getTetanusProtected());
        self::assertSame([IsolationCode::Covid19, IsolationCode::Mrsa], $visit->getIsolation());
    }

    public function testVisitWithoutAssessmentContactIsAnchoredOnAdmission(): void
    {
        $hospital = $this->encounterHospital();
        $import = $this->encounterImport($hospital, 'A');

        $this->encounterRepository()->insertIfNew(new Encounter(
            hospital: $hospital,
            import: $import,
            keyGeneration: 1,
            visitFingerprint: self::fingerprintFor('2026-10-13'),
            caseKey: hash('sha256', 'case|minimal'),
            age: 1,
            sex: Sex::Male,
            times: new EncounterTimes(edAdmissionAt: new \DateTimeImmutable('2026-10-13 00:01')),
        ));

        $em = $this->encounterEntityManager();
        $em->clear();
        $visit = $em->getRepository(Encounter::class)->findOneBy(['visitFingerprint' => self::fingerprintFor('2026-10-13')]);
        self::assertInstanceOf(Encounter::class, $visit);
        self::assertSame(AnchorSource::Admission, $visit->getAnchorSource());
        self::assertEquals(new \DateTimeImmutable('2026-10-13 00:01'), $visit->getAnchorAt());
        self::assertNull($visit->getVitals()->getReference());
        self::assertNull($visit->getDisposition()->getCode());
        self::assertNull($visit->getWorkAccident(), 'not documented');
        self::assertNull($visit->getTetanusProtected(), 'not documented');
        self::assertSame(ArrivalRoute::Unknown, $visit->getArrivalRoute());
        self::assertSame([], $visit->getIsolation());
    }
}
