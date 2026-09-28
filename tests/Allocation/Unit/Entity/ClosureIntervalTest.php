<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Entity;

use App\Allocation\Domain\Entity\ClosureInterval;
use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\Speciality;
use App\Allocation\Domain\Enum\ClosureCareLevel;
use App\Allocation\Domain\Enum\ClosureFacilityKind;
use App\Allocation\Domain\Enum\ClosureReason;
use App\Import\Domain\Entity\Import;
use PHPUnit\Framework\TestCase;

final class ClosureIntervalTest extends TestCase
{
    public function testStoresIntervalFieldsAndDerivesDuration(): void
    {
        $startsAt = new \DateTimeImmutable('2026-01-01 00:10:00');
        $endsAt = new \DateTimeImmutable('2026-01-01 01:10:00');
        $recordedAt = new \DateTimeImmutable('2026-01-01 00:20:22');
        $changedAt = new \DateTimeImmutable('2026-01-01 00:25:00');

        $interval = new ClosureInterval()
            ->setHospital(new Hospital())
            ->setImport(new Import())
            ->setSpeciality(new Speciality())
            ->setDepartment(new Department())
            ->setStartsAt($startsAt)
            ->setEndsAt($endsAt)
            ->setCareLevel(ClosureCareLevel::EMERGENCY)
            ->setReason(ClosureReason::EMERGENCY_DEPARTMENT_OVERLOAD)
            ->setFacilityKind(ClosureFacilityKind::CLINIC)
            ->setClosureUnit('COVID Normalstation')
            ->setSourceGroupId('88759901')
            ->setRemark('Schichtnotiz')
            ->setInternalRemark('intern')
            ->setSourceRecordedAt($recordedAt)
            ->setSourceChangedAt($changedAt);

        self::assertNull($interval->getId());
        self::assertInstanceOf(Hospital::class, $interval->getHospital());
        self::assertInstanceOf(Import::class, $interval->getImport());
        self::assertInstanceOf(Speciality::class, $interval->getSpeciality());
        self::assertInstanceOf(Department::class, $interval->getDepartment());
        self::assertSame($startsAt, $interval->getStartsAt());
        self::assertSame($endsAt, $interval->getEndsAt());
        self::assertSame(60, $interval->durationInMinutes());
        self::assertSame(ClosureCareLevel::EMERGENCY, $interval->getCareLevel());
        self::assertSame(ClosureReason::EMERGENCY_DEPARTMENT_OVERLOAD, $interval->getReason());
        self::assertSame(ClosureFacilityKind::CLINIC, $interval->getFacilityKind());
        self::assertSame('COVID Normalstation', $interval->getClosureUnit());
        self::assertSame('88759901', $interval->getSourceGroupId());
        self::assertSame('Schichtnotiz', $interval->getRemark());
        self::assertSame('intern', $interval->getInternalRemark());
        self::assertSame($recordedAt, $interval->getSourceRecordedAt());
        self::assertSame($changedAt, $interval->getSourceChangedAt());
    }

    public function testDurationRequiresStartAndEnd(): void
    {
        $this->expectException(\LogicException::class);

        new ClosureInterval()->durationInMinutes();
    }

    public function testOptionalTextsCanBeCleared(): void
    {
        $interval = new ClosureInterval()
            ->setClosureUnit(null)
            ->setSourceGroupId(null)
            ->setRemark(null)
            ->setInternalRemark(null);

        self::assertNull($interval->getClosureUnit());
        self::assertNull($interval->getSourceGroupId());
        self::assertNull($interval->getRemark());
        self::assertNull($interval->getInternalRemark());
    }
}
