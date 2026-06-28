<?php

declare(strict_types=1);

namespace App\Onboarding\Domain\Enum;

enum OnboardingStepKey: string
{
    case RequestClinicAccess = 'request_clinic_access';
    case VerifyOwnClinic = 'verify_own_clinic';
    case StartFirstImport = 'start_first_import';
    case ViewExploreData = 'view_explore_data';
    case ViewOverviewStatistics = 'view_overview_statistics';

    /**
     * @return list<self>
     */
    public static function orderedCases(): array
    {
        return [
            self::RequestClinicAccess,
            self::VerifyOwnClinic,
            self::StartFirstImport,
            self::ViewExploreData,
            self::ViewOverviewStatistics,
        ];
    }

    public function position(): int
    {
        $index = array_search($this, self::orderedCases(), true);

        return false === $index ? 0 : $index + 1;
    }
}
