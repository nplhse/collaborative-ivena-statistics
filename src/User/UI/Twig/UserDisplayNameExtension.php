<?php

declare(strict_types=1);

namespace App\User\UI\Twig;

use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFunction;

/**
 * The one rule for showing the optional display name of a user (#462): signed-in viewers see it,
 * anonymous visitors (for example on the public blog) only see the username. The UserAvatar
 * component uses the same rule for its initials.
 */
final readonly class UserDisplayNameExtension
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private Security $security,
    ) {
    }

    #[AsTwigFunction('can_see_display_names')]
    public function canSeeDisplayNames(): bool
    {
        return $this->security->isGranted('IS_AUTHENTICATED_REMEMBERED');
    }

    #[AsTwigFunction('user_display_name')]
    public function userDisplayName(?string $displayName, ?string $username): string
    {
        if (null !== $displayName && '' !== trim($displayName) && $this->canSeeDisplayNames()) {
            return $displayName;
        }

        return $username ?? '';
    }
}
