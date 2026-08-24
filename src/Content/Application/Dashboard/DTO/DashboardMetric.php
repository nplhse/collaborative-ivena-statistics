<?php

declare(strict_types=1);

namespace App\Content\Application\Dashboard\DTO;

/** @psalm-suppress PossiblyUnusedProperty Consumed by Twig dashboard templates. */
final readonly class DashboardMetric
{
    public function __construct(
        public string $key,
        public int $value,
        public int $deltaLast30Days,
        public string $icon,
        public string $labelTranslationKey,
    ) {
    }
}
