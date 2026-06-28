<?php

declare(strict_types=1);

namespace App\Shared\Application\Export;

final readonly class ExportEstimate
{
    public function __construct(
        public int $count,
        public bool $blocked,
        public bool $warn,
        public string $exporterKey,
    ) {
    }
}
