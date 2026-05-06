<?php

declare(strict_types=1);

namespace App\Statistics\Application\Report;

final class ReportLimitPolicy
{
    /** @var list<int> */
    private const array ALLOWED = [10, 25, 50];
    private const int DEFAULT = 25;

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

    public function normalize(mixed $rawLimit): int
    {
        if (null === $rawLimit || '' === (string) $rawLimit) {
            return self::DEFAULT;
        }

        $parsed = filter_var((string) $rawLimit, FILTER_VALIDATE_INT);
        if (false !== $parsed && \in_array($parsed, self::ALLOWED, true)) {
            return $parsed;
        }

        return self::DEFAULT;
    }
}
