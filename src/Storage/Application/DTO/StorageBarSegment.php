<?php

declare(strict_types=1);

namespace App\Storage\Application\DTO;

/**
 * One part of the segmented storage bar. The width is a share of the whole bar, which spans the capacity
 * reference when there is one and the usage otherwise; the rest of the bar is free capacity.
 */
final readonly class StorageBarSegment
{
    public const string OUTSIDE_APPLICATION = 'outside_application';

    public function __construct(
        public string $key,
        public string $labelKey,
        public string $color,
        public ?string $icon,
        public int $bytes,
        public float $widthPercent,
    ) {
    }
}
