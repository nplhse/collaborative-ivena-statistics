<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\Menu;

use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * A section of a menu, for example "Comparisons" in the statistics sidebar. Items attach to it by its id.
 *
 * A group without label is rendered without heading; the context that owns the menu defines its groups
 * (ADR 035).
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the navigation components.
 */
final readonly class NavGroup
{
    public function __construct(
        public string $id,
        public ?TranslatableInterface $label = null,
        public int $weight = 0,
        public ?string $icon = null,
    ) {
    }
}
