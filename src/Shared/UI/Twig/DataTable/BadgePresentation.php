<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\DataTable;

enum BadgePresentation: string
{
    case Badge = 'badge';
    case Status = 'status';
}
