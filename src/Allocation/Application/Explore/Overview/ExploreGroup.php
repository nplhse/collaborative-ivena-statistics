<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore\Overview;

/**
 * Groups of the Explore overview (`@Allocation/explore.html.twig`) that other contexts can add tiles to.
 */
enum ExploreGroup: string
{
    case Cases = 'cases';

    case Organisation = 'organisation';

    case Structure = 'structure';

    case Features = 'features';
}
