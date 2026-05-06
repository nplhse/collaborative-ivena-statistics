<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO;

/**
 * Allowed axis pairs: (department, urgency), (age_group, gender), (urgency, gender).
 * Invalid query combinations fall back to the default pair.
 */
final readonly class PivotTableAxes
{
    public function __construct(
        public PivotRowAxis $row,
        public PivotColAxis $col,
    ) {
    }

    public static function default(): self
    {
        return new self(PivotRowAxis::Urgency, PivotColAxis::Gender);
    }

    public static function fromQuery(?string $rowsParam, ?string $colsParam): self
    {
        $row = PivotRowAxis::tryFromQuery($rowsParam);
        $col = PivotColAxis::tryFromQuery($colsParam);

        if ($row instanceof PivotRowAxis && $col instanceof PivotColAxis && self::isAllowedPair($row, $col)) {
            return new self($row, $col);
        }

        return self::default();
    }

    public static function isAllowedPair(PivotRowAxis $row, PivotColAxis $col): bool
    {
        return match ($row) {
            PivotRowAxis::Department => PivotColAxis::Urgency === $col,
            PivotRowAxis::AgeGroup => PivotColAxis::Gender === $col,
            PivotRowAxis::Urgency => PivotColAxis::Gender === $col,
        };
    }
}
