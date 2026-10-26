<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\DataTable;

/**
 * How a DataTable looks on narrow screens.
 *
 * Scroll keeps the regular table with horizontal scrolling. Cards shows each row as a card with
 * labelled values below the md breakpoint; the switch is CSS only.
 */
enum DataTableMobileLayout: string
{
    case Scroll = 'scroll';
    case Cards = 'cards';
}
