<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Navigation;

final class StatisticsQueryParamNormalizer
{
    /**
     * @param array<mixed> $params
     *
     * @return array<string, bool|float|int|string>
     */
    public static function normalize(array $params): array
    {
        $normalized = [];

        foreach ($params as $key => $value) {
            if (!\is_string($key)) {
                continue;
            }

            $scalar = self::normalizeValue($value);
            if (null !== $scalar) {
                $normalized[$key] = $scalar;
            }
        }

        return $normalized;
    }

    private static function normalizeValue(mixed $value): string|int|float|bool|null
    {
        if (\is_string($value) || \is_int($value) || \is_float($value) || \is_bool($value)) {
            return $value;
        }

        return null;
    }
}
