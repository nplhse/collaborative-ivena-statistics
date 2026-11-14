<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\Menu;

use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * A small label next to a navigation entry, for example "Beta". The provider chooses label and colour.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the navigation components.
 */
final readonly class NavBadge
{
    public function __construct(
        public TranslatableInterface $label,
        public NavBadgeColor $color = NavBadgeColor::Azure,
    ) {
    }
}
