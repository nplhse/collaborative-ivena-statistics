<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\Menu;

/**
 * Tabler colour names a navigation badge may use; rendered as `badge bg-{colour}-lt`, so every colour
 * works in light and dark mode without own CSS (ADR 035).
 */
enum NavBadgeColor: string
{
    case Blue = 'blue';
    case Azure = 'azure';
    case Indigo = 'indigo';
    case Purple = 'purple';
    case Pink = 'pink';
    case Red = 'red';
    case Orange = 'orange';
    case Yellow = 'yellow';
    case Lime = 'lime';
    case Green = 'green';
    case Teal = 'teal';
    case Cyan = 'cyan';
    case Secondary = 'secondary';

    public function cssClass(): string
    {
        return 'bg-'.$this->value.'-lt';
    }
}
