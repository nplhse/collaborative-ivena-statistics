<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure\Notification;

/**
 * Where the storage notifications link to: the Disk Usage page in the Admin backend.
 */
final class DiskUsagePage
{
    public const string ROUTE = 'app_admin_dashboard_operations_storage';
}
