<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Pagination;

/**
 * Number of rows behind a cursor list: exact, or a lower bound when only the first rows could be counted.
 */
final readonly class ResultCount
{
    public function __construct(
        public int $count,
        public bool $isLowerBound = false,
    ) {
        if ($count < 0) {
            throw new \InvalidArgumentException('A result count cannot be negative.');
        }
    }

    public static function exact(int $count): self
    {
        return new self($count);
    }

    public static function moreThan(int $count): self
    {
        return new self($count, true);
    }
}
