<?php

declare(strict_types=1);

namespace App\Import\Application\DTO;

final readonly class ImportSummary
{
    public function __construct(
        public int $total,
        public int $ok,
        public int $rejected,
    ) {
    }

    public static function empty(): self
    {
        return new self(0, 0, 0);
    }

    public function rejectionRatio(): float
    {
        if (0 === $this->total) {
            return 0.0;
        }

        return $this->rejected / $this->total;
    }

    public function isEmpty(): bool
    {
        return 0 === $this->total;
    }
}
