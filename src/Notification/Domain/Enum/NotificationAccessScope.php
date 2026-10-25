<?php

declare(strict_types=1);

namespace App\Notification\Domain\Enum;

/**
 * Decides how an inbox entry is shown when the viewer's permissions have changed since publication.
 */
enum NotificationAccessScope: string
{
    /** Always shown. */
    case None = 'none';

    /** Redacted when the viewer no longer has access to the referenced hospital. */
    case Hospital = 'hospital';

    /** Hidden when the viewer no longer has the required role. */
    case Role = 'role';
}
