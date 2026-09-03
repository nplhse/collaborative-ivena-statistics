<?php

declare(strict_types=1);

namespace App\Statistics\Application\TopList;

final class TopListPageSizePolicy
{
    /** @var list<int> */
    public const array ALLOWED = [25, 50, 100];
    public const int DEFAULT = 25;

    /**
     * @return list<int>
     */
    public function allowed(): array
    {
        return self::ALLOWED;
    }

    public function default(): int
    {
        return self::DEFAULT;
    }

    public function normalize(mixed $rawPageSize): int
    {
        $parsed = filter_var((string) $rawPageSize, FILTER_VALIDATE_INT);
        if (false !== $parsed && \in_array($parsed, self::ALLOWED, true)) {
            return $parsed;
        }

        return self::DEFAULT;
    }
}
