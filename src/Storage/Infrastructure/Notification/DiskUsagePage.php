<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure\Notification;

/**
 * Where the storage notifications link to.
 *
 * TODO(#737, step 4/5): switch to 'app_admin_dashboard_operations_storage' once the Disk Usage page is merged.
 */
final class DiskUsagePage
{
    public const string ROUTE = 'app_admin_dashboard';
}
