<?php

declare(strict_types=1);

namespace App\Analytics\Domain\Enum;

enum SessionBoundaryKind: string
{
    case Entry = 'entry';
    case Exit = 'exit';
}
