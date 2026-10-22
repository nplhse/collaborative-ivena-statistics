<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Notification;

use App\Allocation\Application\Service\HospitalPermissionAccess;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Notification\Application\Contract\NotificationHospitalAccessInterface;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * A viewer may still see a hospital-scoped notification while they have View permission on it (ADR 002).
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for NotificationHospitalAccessInterface.
 */
#[AsAlias(NotificationHospitalAccessInterface::class)]
final readonly class HospitalPermissionNotificationAccess implements NotificationHospitalAccessInterface
{
    public function __construct(
        private HospitalPermissionAccess $hospitalPermissionAccess,
    ) {
    }

    #[\Override]
    public function filterAccessible(User $viewer, array $hospitalIds): array
    {
        if ([] === $hospitalIds) {
            return [];
        }

        if (\in_array(UserRole::ADMIN, $viewer->getRoles(), true)) {
            return array_values(array_unique($hospitalIds));
        }

        $accessible = $this->hospitalPermissionAccess->resolveHospitalIdsWithPermission($viewer, HospitalPermission::View);

        return array_values(array_intersect(array_unique($hospitalIds), $accessible));
    }
}
