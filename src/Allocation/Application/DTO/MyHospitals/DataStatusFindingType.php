<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO\MyHospitals;

use App\Allocation\Domain\Enum\HospitalPermission;

enum DataStatusFindingType: string
{
    case ImportFailed = 'import_failed';
    case ImportCancelled = 'import_cancelled';
    case ImportRejects = 'import_rejects';
    case ImportDayGaps = 'import_day_gaps';
    case NotUpdated = 'not_updated';
    case PossibleGap = 'possible_gap';
    case VolumeChange = 'volume_change';

    /**
     * The permission a user needs on the hospital to see this finding.
     */
    public function requiredPermission(): HospitalPermission
    {
        return match ($this) {
            self::ImportFailed, self::ImportCancelled, self::ImportRejects, self::ImportDayGaps => HospitalPermission::Import,
            self::PossibleGap, self::VolumeChange => HospitalPermission::Statistics,
            self::NotUpdated => HospitalPermission::View,
        };
    }
}
