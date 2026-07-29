<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore\Catalog;

/**
 * Small-cell suppression for catalog coverage metrics.
 */
final class CatalogPrivacyPolicy
{
    public const int MIN_ALLOCATIONS = 5;
}
