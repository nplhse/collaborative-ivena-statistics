<?php

declare(strict_types=1);

namespace App\Statistics\Application\TopList;

final class TopListLimitPolicy
{
    /** @var list<int> */
    private const array ALLOWED_NUMERIC = [10, 25, 50, 100];
    private const int DEFAULT = 25;

    /**
     * @return list<TopListLimit>
     */
    public function allowed(): array
    {
        $limits = [];
        foreach (self::ALLOWED_NUMERIC as $value) {
            $limits[] = TopListLimit::of($value);
        }
        $limits[] = TopListLimit::all();

        return $limits;
    }

    public function default(): TopListLimit
    {
        return TopListLimit::of(self::DEFAULT);
    }

    public function normalize(mixed $rawLimit): TopListLimit
    {
        if (null === $rawLimit || '' === (string) $rawLimit) {
            return $this->default();
        }

        if (TopListLimit::ALL === strtolower((string) $rawLimit)) {
            return TopListLimit::all();
        }

        $parsed = filter_var((string) $rawLimit, FILTER_VALIDATE_INT);
        if (false !== $parsed && \in_array($parsed, self::ALLOWED_NUMERIC, true)) {
            return TopListLimit::of($parsed);
        }

        return $this->default();
    }
}
