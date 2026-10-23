<?php

declare(strict_types=1);

namespace App\Notification\Application\Contract;

use App\User\Domain\Entity\User;

/**
 * Tells the notification inbox which of the referenced hospitals a viewer may still see (ADR 016,
 * "Data minimization and access"). Implemented by the context that owns hospital permissions.
 */
interface NotificationHospitalAccessInterface
{
    /**
     * @param list<int> $hospitalIds
     *
     * @return list<int> the subset the viewer currently has access to
     */
    public function filterAccessible(User $viewer, array $hospitalIds): array;
}
