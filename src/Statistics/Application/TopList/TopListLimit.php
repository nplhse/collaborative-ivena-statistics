<?php

declare(strict_types=1);

namespace App\Statistics\Application\TopList;

final readonly class TopListLimit
{
    public const string ALL = 'all';
    public const int ALL_SAFETY_CAP = 1000;
    public const int ALL_PAGE_SIZE = 50;

    private function __construct(
        private int $numericValue,
        public bool $isAll,
    ) {
    }

    public static function of(int $value): self
    {
        return new self($value, false);
    }

    public static function all(): self
    {
        return new self(self::ALL_SAFETY_CAP, true);
    }

    public function queryValue(): int|string
    {
        return $this->isAll ? self::ALL : $this->numericValue;
    }

    public function queryLimit(): int
    {
        return $this->isAll ? self::ALL_SAFETY_CAP + 1 : $this->numericValue;
    }

    public function equals(self $other): bool
    {
        return $this->isAll === $other->isAll && $this->numericValue === $other->numericValue;
    }
}
