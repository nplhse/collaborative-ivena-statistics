<?php

declare(strict_types=1);

namespace App\Storage\Application\Notification;

use App\Storage\Domain\Enum\CapacityLevel;

/**
 * The alert rule of ADR 017 ("Notifications"): a storage alert is due whenever the measured level differs
 * from the last notified one, in either direction. An unchanged level, however long it lasts, and an
 * unknown level are no change.
 */
final readonly class CapacityLevelChange
{
    private function __construct(
        public CapacityLevel $from,
        public CapacityLevel $to,
    ) {
    }

    public static function between(CapacityLevel $lastNotified, CapacityLevel $measured): ?self
    {
        if (!$measured->isKnown()) {
            return null;
        }

        // The store never holds unknown; should it, nothing was announced yet.
        $from = $lastNotified->isKnown() ? $lastNotified : CapacityLevel::Normal;

        return $from === $measured ? null : new self($from, $measured);
    }

    public function direction(): CapacityAlertDirection
    {
        if ((int) $this->to->rank() > (int) $this->from->rank()) {
            return CapacityAlertDirection::Escalated;
        }

        return CapacityLevel::Normal === $this->to ? CapacityAlertDirection::Resolved : CapacityAlertDirection::DeEscalated;
    }
}
