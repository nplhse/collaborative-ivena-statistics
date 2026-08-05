<?php

declare(strict_types=1);

namespace App\Analytics\Application\RequestTracking;

final class QueryParameterNameExtractor
{
    /**
     * @param array<string, mixed> $queryAll
     *
     * @return list<string>
     */
    public function extract(array $queryAll): array
    {
        $names = [];
        foreach ($queryAll as $name => $value) {
            if ('' === $name) {
                continue;
            }

            if ($this->isEmptyValue($value)) {
                continue;
            }

            $names[] = $name;
        }

        sort($names);

        return array_values(array_unique($names));
    }

    private function isEmptyValue(mixed $value): bool
    {
        if (null === $value) {
            return true;
        }

        if (\is_string($value)) {
            return '' === trim($value);
        }

        if (\is_array($value)) {
            if ([] === $value) {
                return true;
            }

            return array_all($value, fn ($item): bool => $this->isEmptyValue($item));
        }

        return false;
    }
}
