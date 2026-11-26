<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\EditSection;

/**
 * One sidebar group with links on the hospital edit pages, contributed by a provider.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the hospital edit shell template.
 */
final readonly class HospitalEditSection
{
    /**
     * @param list<HospitalEditSectionLink> $links
     */
    public function __construct(
        public string $key,
        public string $titleKey,
        public string $translationDomain,
        public array $links,
    ) {
    }
}
