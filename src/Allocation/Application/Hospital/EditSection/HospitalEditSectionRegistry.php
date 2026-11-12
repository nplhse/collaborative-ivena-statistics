<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\EditSection;

use App\Allocation\Domain\Entity\Hospital;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class HospitalEditSectionRegistry
{
    /**
     * @param iterable<HospitalEditSectionProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator(HospitalEditSectionProviderInterface::TAG)]
        private iterable $providers,
    ) {
    }

    /**
     * @return list<HospitalEditSection>
     */
    public function sectionsFor(Hospital $hospital): array
    {
        $sections = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->sectionsFor($hospital) as $section) {
                $sections[] = $section;
            }
        }

        return $sections;
    }
}
