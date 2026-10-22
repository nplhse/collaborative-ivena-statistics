<?php

declare(strict_types=1);

namespace App\Notification\Application\Inbox;

use App\Notification\Application\Contract\NotificationHospitalAccessInterface;
use App\Notification\Domain\Enum\NotificationAccessScope;
use App\User\Domain\Entity\User;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Applies the render-time permission rules of ADR 016: hospital-scoped entries are redacted after
 * access was revoked, role-scoped entries are hidden without the required role.
 */
final readonly class NotificationVisibilityResolver
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private NotificationHospitalAccessInterface $hospitalAccess,
        private RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    /**
     * @param list<InboxEntry> $entries
     *
     * @return array<int, NotificationVisibility> keyed by entry id
     */
    public function resolve(User $viewer, array $entries): array
    {
        $hospitalIds = [];
        foreach ($entries as $entry) {
            if (NotificationAccessScope::Hospital === $entry->accessScope && null !== $entry->scopeReferenceId) {
                $hospitalIds[$entry->scopeReferenceId] = $entry->scopeReferenceId;
            }
        }

        $accessibleHospitals = [] === $hospitalIds
            ? []
            : array_flip($this->hospitalAccess->filterAccessible($viewer, array_values($hospitalIds)));
        $roles = $this->roleHierarchy->getReachableRoleNames($viewer->getRoles());

        $visibility = [];
        foreach ($entries as $entry) {
            $visibility[$entry->id] = match ($entry->accessScope) {
                NotificationAccessScope::None => NotificationVisibility::Visible,
                NotificationAccessScope::Hospital => null !== $entry->scopeReferenceId && isset($accessibleHospitals[$entry->scopeReferenceId])
                    ? NotificationVisibility::Visible
                    : NotificationVisibility::Redacted,
                NotificationAccessScope::Role => null !== $entry->requiredRole && \in_array($entry->requiredRole, $roles, true)
                    ? NotificationVisibility::Visible
                    : NotificationVisibility::Hidden,
            };
        }

        return $visibility;
    }
}
