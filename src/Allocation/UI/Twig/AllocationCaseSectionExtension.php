<?php

declare(strict_types=1);

namespace App\Allocation\UI\Twig;

use App\Allocation\Application\CaseFile\AllocationCaseSection;
use App\Allocation\Application\CaseFile\AllocationCaseSectionRegistry;
use App\Allocation\Domain\Entity\Allocation;

final readonly class AllocationCaseSectionExtension
{
    public function __construct(
        private AllocationCaseSectionRegistry $registry,
    ) {
    }

    /**
     * @return list<AllocationCaseSection>
     */
    #[\Twig\Attribute\AsTwigFunction(name: 'allocation_case_sections')]
    public function sections(Allocation $allocation): array
    {
        return $this->registry->sectionsFor($allocation);
    }
}
