<?php

declare(strict_types=1);

namespace App\Shared\Application\Export;

final class ExportLimits
{
    public const int MAX_EXPORT_ROWS = 50_000;

    public const int WARN_EXPORT_ROWS = 40_000;
}
