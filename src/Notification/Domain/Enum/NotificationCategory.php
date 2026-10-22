<?php

declare(strict_types=1);

namespace App\Notification\Domain\Enum;

/**
 * User-facing groups for notification types and category-level preferences.
 */
enum NotificationCategory: string
{
    case Imports = 'imports';
    case AnalysesExports = 'analyses_exports';
    case Account = 'account';
    case Reports = 'reports';
    case Announcements = 'announcements';
    case Administration = 'administration';
}
