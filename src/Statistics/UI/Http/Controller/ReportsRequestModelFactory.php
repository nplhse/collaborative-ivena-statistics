<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

final class ReportsRequestModelFactory
{
    private const array ALLOWED_LIMITS = [10, 25, 50];

    public function fromQuery(array $query): ReportsRequestModel
    {
        $reportKey = isset($query['report']) ? (string) $query['report'] : '';

        return new ReportsRequestModel(
            $reportKey,
            $this->resolveLimit($query['limit'] ?? null),
        );
    }

    private function resolveLimit(mixed $rawLimit): int
    {
        if (null === $rawLimit || '' === (string) $rawLimit) {
            return 25;
        }

        $parsed = filter_var((string) $rawLimit, FILTER_VALIDATE_INT);
        if (false !== $parsed && \in_array($parsed, self::ALLOWED_LIMITS, true)) {
            return $parsed;
        }

        return 25;
    }
}
