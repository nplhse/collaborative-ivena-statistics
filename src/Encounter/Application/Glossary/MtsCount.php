<?php

declare(strict_types=1);

namespace App\Encounter\Application\Glossary;

use App\Allocation\Application\Explore\Catalog\CatalogPrivacyPolicy;

/**
 * An encounter count shown in the MTS glossary, with small-cell suppression as in the Explore
 * catalog (ADR 012): counts of 1 to `CatalogPrivacyPolicy::MIN_ALLOCATIONS - 1` are not shown.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the glossary templates.
 */
final readonly class MtsCount
{
    public const int THRESHOLD = CatalogPrivacyPolicy::MIN_ALLOCATIONS;

    public bool $suppressed;

    public ?int $value;

    public function __construct(int $count)
    {
        $this->suppressed = $count > 0 && $count < self::THRESHOLD;
        $this->value = $this->suppressed ? null : $count;
    }
}
