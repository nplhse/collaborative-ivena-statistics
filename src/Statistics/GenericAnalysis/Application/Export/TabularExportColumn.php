<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application\Export;

final readonly class TabularExportColumn
{
    public function __construct(
        public string $key,
        public string $label,
    ) {
    }
}
