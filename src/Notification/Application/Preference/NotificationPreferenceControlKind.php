<?php

declare(strict_types=1);

namespace App\Notification\Application\Preference;

enum NotificationPreferenceControlKind: string
{
    /** The user may choose default, on or off. */
    case Configurable = 'configurable';

    /** Always used, regardless of preferences. */
    case Mandatory = 'mandatory';

    /** The type is not configurable; its default applies. */
    case Fixed = 'fixed';

    /** The channel is not offered for this category or type. */
    case Unavailable = 'unavailable';
}
