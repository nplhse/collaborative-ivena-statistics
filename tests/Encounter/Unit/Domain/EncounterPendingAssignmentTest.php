<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Domain;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Contract\Assignment\AssignmentKind;
use App\Encounter\Domain\Entity\EncounterPendingAssignment;
use App\Import\Domain\Entity\Import;
use PHPUnit\Framework\TestCase;

final class EncounterPendingAssignmentTest extends TestCase
{
    public function testAnAssignedRecordIsNotPending(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('An assigned record is not pending.');

        new EncounterPendingAssignment(new Hospital(), 1, new Import(), 'probe', 'r1', AssignmentKind::Assigned, null, null, null, [], new \DateTimeImmutable('2026-10-12 11:00'));
    }
}
