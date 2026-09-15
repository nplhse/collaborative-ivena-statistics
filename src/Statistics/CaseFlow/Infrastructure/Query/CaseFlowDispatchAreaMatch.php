<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Infrastructure\Query;

/**
 * How dispatch-area scope selects allocation_stats_projection rows.
 *
 * Origin: cases that started in the Leitstelle.
 * Catchment: cases assigned to hospitals that belong to the Leitstelle.
 * Related: union of both (inflow + local stays + outflow).
 */
enum CaseFlowDispatchAreaMatch
{
    case Origin;
    case Catchment;
    case Related;
}
