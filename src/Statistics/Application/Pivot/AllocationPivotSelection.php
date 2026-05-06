<?php

declare(strict_types=1);

namespace App\Statistics\Application\Pivot;

final readonly class AllocationPivotSelection
{
    public function __construct(
        public AllocationPivotDimension $rows,
        public AllocationPivotDimension $cols,
        public AllocationPivotMeasure $measure,
    ) {
    }

    public static function default(): self
    {
        return new self(
            AllocationPivotDimension::Urgency,
            AllocationPivotDimension::Gender,
            AllocationPivotMeasure::Count,
        );
    }

    public static function fromQuery(?string $rows, ?string $cols, ?string $measure): self
    {
        return new self(
            AllocationPivotDimension::fromQuery($rows) ?? self::default()->rows,
            AllocationPivotDimension::fromQuery($cols) ?? self::default()->cols,
            AllocationPivotMeasure::fromQuery($measure) ?? self::default()->measure,
        );
    }
}
