<?php

declare(strict_types=1);

namespace App\Encounter\Application\Plausibility;

/**
 * Which cell gave the bonus of a pair (ADR 033): the exact cell (PZC × diagram), the fallback cell of the PZC's problem
 * area × diagram, or none (bonus 0).
 */
enum PlausibilityLevel: string
{
    case Exact = 'exact';
    case Area = 'area';
    case None = 'none';
}
