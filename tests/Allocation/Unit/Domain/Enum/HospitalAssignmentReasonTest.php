<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Domain\Enum;

use App\Allocation\Domain\Enum\HospitalAssignmentReason;
use PHPUnit\Framework\TestCase;

final class HospitalAssignmentReasonTest extends TestCase
{
    public function testTheReasonSuggestsTheGrantPermissions(): void
    {
        // VIEW 1, STATISTICS 2, IMPORT 4, EXPORT 8, BENCHMARKING 16
        self::assertSame(31, HospitalAssignmentReason::EmergencyDepartmentLeadership->suggestedPermissions());
        self::assertSame(31, HospitalAssignmentReason::HospitalManagement->suggestedPermissions());
        self::assertSame(7, HospitalAssignmentReason::DataSubmission->suggestedPermissions());
        self::assertSame(3, HospitalAssignmentReason::Other->suggestedPermissions());
    }

    public function testOnlyOtherNeedsAnExplanation(): void
    {
        self::assertTrue(HospitalAssignmentReason::Other->requiresExplanation());
        self::assertFalse(HospitalAssignmentReason::DataSubmission->requiresExplanation());
    }
}
