<?php

declare(strict_types=1);

namespace App\Research\Domain\Enum;

/**
 * What an export query delivers (ADR 045). Aggregated queries return the reserved column `_hospital_ids` per row,
 * single-case queries `_hospital_id`; the runner checks the minimum of contributing hospitals with it and drops it.
 */
enum QueryKind: string
{
    case Aggregated = 'aggregated';
    case SingleCases = 'single_cases';

    public function reservedColumn(): string
    {
        return self::Aggregated === $this ? '_hospital_ids' : '_hospital_id';
    }

    public function labelKey(): string
    {
        return 'data_request.query.kind.'.$this->value;
    }
}
