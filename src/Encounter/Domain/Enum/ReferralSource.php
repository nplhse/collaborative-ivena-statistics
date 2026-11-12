<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Enum;

/**
 * `referral_source` of the `ed_visit` exchange format (#1047).
 */
enum ReferralSource: string
{
    case Self = 'self';
    case Ems = 'ems';
    case EmergencyPhysician = 'emergency_physician';
    case HospitalTransfer = 'hospital_transfer';
    case Physician = 'physician';
    case KvPractice = 'kv_practice';
    case Other = 'other';
}
