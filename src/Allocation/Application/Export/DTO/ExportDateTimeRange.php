<?php

declare(strict_types=1);

namespace App\Allocation\Application\Export\DTO;

final readonly class ExportDateTimeRange
{
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
    ) {
    }
}
