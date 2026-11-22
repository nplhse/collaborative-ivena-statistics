<?php

declare(strict_types=1);

namespace App\Onboarding\Domain\Enum;

/**
 * Items of the "What's new for you" card (#1485). A release lists its items in `WhatsNewCatalog`.
 */
enum WhatsNewItemKey: string
{
    case DisplayName = 'display_name';
    case MyHospitals = 'my_hospitals';
    case SettingsOverview = 'settings_overview';
}
