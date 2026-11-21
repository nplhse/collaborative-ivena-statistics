<?php

declare(strict_types=1);

namespace App\Allocation\Domain\Enum;

/**
 * Why a participant asks to be assigned to a hospital (#975). The reason suggests the permissions of an access
 * grant; the reviewer can change them. It is never proof of authority over the hospital.
 */
enum HospitalAssignmentReason: string
{
    case EmergencyDepartmentLeadership = 'ed_leadership';
    case HospitalManagement = 'hospital_management';
    case DataSubmission = 'data_submission';
    case Other = 'other';

    /** Permissions an approval as access grant starts with. */
    public function suggestedPermissions(): int
    {
        $view = HospitalPermission::View->value;
        $statistics = HospitalPermission::Statistics->value;

        return match ($this) {
            self::EmergencyDepartmentLeadership, self::HospitalManagement => $view | $statistics | HospitalPermission::Benchmarking->value | HospitalPermission::Import->value | HospitalPermission::Export->value,
            self::DataSubmission => $view | $statistics | HospitalPermission::Import->value,
            self::Other => $view | $statistics,
        };
    }

    public function requiresExplanation(): bool
    {
        return self::Other === $this;
    }
}
