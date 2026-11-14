<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\Menu;

/**
 * The named menus of the navigation tree (ADR 035).
 *
 * The sidebar menus belong to one entry of the primary menu: while a page of a sidebar menu is shown,
 * that entry is the active area in the top navbar.
 */
enum Menu: string
{
    case Primary = 'primary';
    case Footer = 'footer';
    case Statistics = 'statistics';
    case Explore = 'explore';

    /**
     * Id of the one untitled group of the primary menu, defined by Shared; contexts attach their area
     * entries to it.
     */
    public const string PRIMARY_GROUP = 'primary';

    /**
     * Ids of the footer columns, defined by Shared; contexts attach their links to them.
     */
    public const string FOOTER_GROUP_PROJECT = 'project';
    public const string FOOTER_GROUP_HELP = 'help';
    public const string FOOTER_GROUP_LEGAL = 'legal';
    public const string FOOTER_GROUP_MORE = 'more';

    /**
     * Id of the primary entry this menu belongs to, or null for the primary and footer menus.
     */
    public function primaryEntryId(): ?string
    {
        return match ($this) {
            self::Statistics => 'statistics',
            self::Explore => 'explore',
            self::Primary, self::Footer => null,
        };
    }
}
