<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Dataset;

/**
 * What a dataset run does with generated data that is already in the period (#877, ADR 022).
 *
 * Real imports (anything that is not a synthetic marker) are never touched: a hospital with real data in
 * the period is blocked in every mode.
 */
enum ExistingData: string
{
    /** Default: any generated data of the hospital in the period blocks it. */
    case Refuse = 'refuse';

    /** Complete generated months are kept; missing and incomplete months are generated. */
    case Fill = 'fill';

    /** Generated months and scenario imports of the hospital in the period are deleted and generated again. */
    case Replace = 'replace';
}
