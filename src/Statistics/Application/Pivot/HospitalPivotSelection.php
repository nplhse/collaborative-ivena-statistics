<?php

declare(strict_types=1);

namespace App\Statistics\Application\Pivot;

final readonly class HospitalPivotSelection
{
    public function __construct(
        public HospitalPivotDimension $rows,
        public HospitalPivotDimension $cols,
        public HospitalPivotMeasure $measure,
    ) {
    }

    public static function default(): self
    {
        return new self(
            HospitalPivotDimension::State,
            HospitalPivotDimension::Tier,
            HospitalPivotMeasure::HospitalCount,
        );
    }

    public static function fromQuery(?string $rows, ?string $cols, ?string $measure): self
    {
        return new self(
            HospitalPivotDimension::fromQuery($rows) ?? self::default()->rows,
            HospitalPivotDimension::fromQuery($cols) ?? self::default()->cols,
            HospitalPivotMeasure::fromQuery($measure) ?? self::default()->measure,
        );
    }
}
