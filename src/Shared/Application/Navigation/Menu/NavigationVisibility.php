<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\Menu;

/**
 * Whether a group or item of a menu is shown, and if not, why (ADR 035, {@see NavigationContributions::visibility()}).
 */
enum NavigationVisibility: string
{
    case Visible = 'visible';

    /** The item's own `grantedAttribute` fails. */
    case Denied = 'denied';

    /** A parent item is hidden, so its children are too. */
    case HiddenParent = 'hidden_parent';

    /** An item without route or URL whose children are all hidden. */
    case NoVisibleChildren = 'no_visible_children';

    /** A group without any visible item. */
    case Empty = 'empty';

    /** An item with an unknown parent or in a parent cycle. */
    case Orphaned = 'orphaned';
}
