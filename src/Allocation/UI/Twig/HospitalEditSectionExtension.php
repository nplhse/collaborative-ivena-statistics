<?php

declare(strict_types=1);

namespace App\Allocation\UI\Twig;

use App\Allocation\Application\Hospital\EditSection\HospitalEditSection;
use App\Allocation\Application\Hospital\EditSection\HospitalEditSectionRegistry;
use App\Allocation\Domain\Entity\Hospital;

final readonly class HospitalEditSectionExtension
{
    public function __construct(
        private HospitalEditSectionRegistry $registry,
    ) {
    }

    /**
     * @return list<HospitalEditSection>
     */
    #[\Twig\Attribute\AsTwigFunction(name: 'hospital_edit_sections')]
    public function sections(Hospital $hospital): array
    {
        return $this->registry->sectionsFor($hospital);
    }
}
