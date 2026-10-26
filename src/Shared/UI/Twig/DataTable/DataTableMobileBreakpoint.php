<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\DataTable;

/**
 * Width of the DataTable below which the cards layout replaces the table (Tabler breakpoint names):
 * sm = 576 px, md = 768 px, lg = 992 px. The CSS in assets/styles/app.css has one container query per case.
 *
 * It is a container query on the table's own width, not on the viewport, so a table in a narrow column
 * switches earlier. Pick a wider breakpoint for tables with many or wide columns.
 */
enum DataTableMobileBreakpoint: string
{
    case Sm = 'sm';
    case Md = 'md';
    case Lg = 'lg';
}
