<?php

declare(strict_types=1);

namespace App\User\UI\Twig\Components;

use App\User\Domain\Entity\User;
use App\User\UI\Twig\UserDisplayNameExtension;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Generated avatar of a user (#462): initials of the optional display name on a colour picked from
 * the numeric user ID, or a neutral user icon. Takes a User entity or the scalar fields of a DTO;
 * it never queries the database. Initials and the name follow UserDisplayNameExtension, so
 * anonymous visitors always get the icon.
 */
#[AsTwigComponent(name: 'UserAvatar', template: '@User/components/UserAvatar.html.twig')]
final class UserAvatar
{
    /**
     * Tabler colours for the `-lt` tint; `.user-avatar` in assets/styles/app.css adjusts the initials
     * to at least 4.5:1 in light and dark mode. The order is part of the contract: changing it
     * changes the colour of every user.
     */
    public const array PALETTE = ['blue', 'azure', 'indigo', 'purple', 'pink', 'red', 'green', 'teal'];

    private const array SIZES = ['inline', 'xs', 'sm', 'md', 'lg', 'xl'];

    public ?User $user = null;

    public ?int $userId = null;

    public ?string $displayName = null;

    public ?string $username = null;

    public string $size = 'sm';

    /** Decorative avatars sit next to the visible name and are hidden from assistive technology. */
    public bool $decorative = true;

    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private readonly UserDisplayNameExtension $displayNames,
    ) {
    }

    public function getInitials(): ?string
    {
        $displayName = $this->user?->getDisplayName() ?? $this->displayName;
        if (null === $displayName || !$this->displayNames->canSeeDisplayNames()) {
            return null;
        }

        $parts = preg_split('/\s+/u', trim($displayName), -1, \PREG_SPLIT_NO_EMPTY);
        if (false === $parts || [] === $parts) {
            return null;
        }

        $first = $this->initialOf($parts[0]);
        $last = \count($parts) > 1 ? $this->initialOf($parts[\count($parts) - 1]) : '';

        $initials = $first.$last;

        return '' === $initials ? null : $initials;
    }

    public function getColour(): string
    {
        $userId = $this->user?->getId() ?? $this->userId;
        if (null === $userId) {
            return 'secondary';
        }

        return self::PALETTE[abs($userId) % \count(self::PALETTE)];
    }

    public function getSizeClass(): string
    {
        $size = \in_array($this->size, self::SIZES, true) ? $this->size : 'sm';

        return 'avatar-'.$size;
    }

    public function getLabel(): string
    {
        return $this->displayNames->userDisplayName(
            $this->user?->getDisplayName() ?? $this->displayName,
            $this->user?->getUserIdentifier() ?? $this->username,
        );
    }

    private function initialOf(string $part): string
    {
        $normalized = \Normalizer::normalize($part, \Normalizer::FORM_C);
        $grapheme = grapheme_substr(false === $normalized ? $part : $normalized, 0, 1);
        if (!\is_string($grapheme) || 1 !== preg_match('/^\p{L}/u', $grapheme)) {
            return '';
        }

        return mb_strtoupper($grapheme);
    }
}
