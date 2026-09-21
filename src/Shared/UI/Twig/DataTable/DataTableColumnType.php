<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\DataTable;

enum DataTableColumnType: string
{
    case Text = 'text';
    case Number = 'number';
    case DateTime = 'datetime';
    case Link = 'link';
    case User = 'user';
    case Badge = 'badge';
    case Boolean = 'boolean';
    case Actions = 'actions';
    case Custom = 'custom';
}
