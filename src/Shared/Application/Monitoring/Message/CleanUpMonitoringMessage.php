<?php

declare(strict_types=1);

namespace App\Shared\Application\Monitoring\Message;

/**
 * Daily scheduler job: folds old hourly Messenger counters into daily rows and deletes monitoring data past its
 * retention (ADR 019).
 */
final readonly class CleanUpMonitoringMessage
{
}
